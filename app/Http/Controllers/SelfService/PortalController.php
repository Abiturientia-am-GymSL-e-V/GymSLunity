<?php

namespace App\Http\Controllers\SelfService;

use App\Documents\SignatureImage;
use App\Http\Controllers\Controller;
use App\Members\MemberFields;
use App\Models\ClubSetting;
use App\Models\Member;
use App\Models\MemberFieldDefinition;
use App\Rules\Iban;
use App\Security\MemberDocumentStore;
use App\SelfService\Access;
use App\SelfService\Documents;
use App\SelfService\FormTemplates;
use App\SelfService\ProfileChanges;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class PortalController extends Controller
{
    public const PROFILE = ['first_name', 'middle_name', 'last_name', 'birth_date', 'mobile_phone', 'street', 'postal_code', 'city', 'country'];

    /** @return array<string, mixed> */
    private function profileRules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'], 'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'], 'birth_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'mobile_phone' => ['nullable', 'string', 'max:50'], 'street' => ['required', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:20'], 'city' => ['required', 'string', 'max:255'], 'country' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    private function membershipOptions(): array
    {
        $field = MemberFieldDefinition::query()->where('key', 'membership_type')->firstOrFail();
        $descriptor = MemberFields::descriptor($field);

        return Arr::except($descriptor['activeOptions'], 'Kontakt');
    }

    public function index(Request $request): Response
    {
        $member = Access::member($request);

        return Inertia::render('selfservice/Portal', [
            'member' => Arr::only($member->attributesToArray(), [...self::PROFILE, 'member_number', 'email', 'membership_type', 'joined_at', 'left_at', 'lock_version']),
            'documents' => DB::table('member_documents')->where('member_id', $member->id)->whereIn('kind', ['application', 'sepa'])->pluck('kind'),
            'canJoin' => $member->membership_type === 'Kontakt' && $member->joined_at === null && ! DB::table('membership_applications')->where('member_id', $member->id)->exists(),
            'pendingApplication' => DB::table('membership_applications')->where('member_id', $member->id)->whereNull('approved_at')->first(['membership_type', 'submitted_at']),
        ]);
    }

    public function update(Request $request, ProfileChanges $changes): RedirectResponse
    {
        $member = Access::member($request);
        $values = $request->validate([...$this->profileRules(), 'lock_version' => ['required', 'integer']]);
        DB::transaction(function () use ($member, $values, $changes): void {
            $current = Member::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            $this->checkVersion($current, (int) $values['lock_version']);
            $changes->save($current, Arr::only($values, self::PROFILE));
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Deine persönlichen Daten wurden gespeichert.']);

        return back();
    }

    public function form(Request $request, string $kind): Response
    {
        abort_unless(in_array($kind, ['application', 'sepa'], true), 404);
        $email = Access::email($request);
        $member = $request->session()->get('selfservice.member_id') ? Access::member($request) : null;
        if ($kind === 'sepa') {
            abort_unless($member && $member->joined_at && $member->left_at === null, 403);
        } else {
            $this->allowJoin($member);
        }
        abort_if($member && DB::table('member_documents')->where('member_id', $member->id)->where('kind', $kind)->exists(), 409, 'Dieses Dokument liegt bereits vor. Änderungen sind über die Verwaltung möglich.');

        return Inertia::render('selfservice/Form', [
            'kind' => $kind, 'email' => $email,
            'requiresApproval' => (ClubSetting::current()->data['membership_activation'] ?? 'immediate') === 'approval',
            'member' => $member ? Arr::only($member->attributesToArray(), [...self::PROFILE, 'lock_version']) : null,
            'texts' => FormTemplates::rendered(), 'version' => ClubSetting::current()->version,
            'membershipOptions' => $this->membershipOptions(),
        ]);
    }

    public function submit(Request $request, string $kind, Documents $documents, ProfileChanges $changes): RedirectResponse
    {
        abort_unless(in_array($kind, ['application', 'sepa'], true), 404);
        $email = Access::email($request);
        $member = $request->session()->get('selfservice.member_id') ? Access::member($request) : null;
        $rules = [
            'version' => ['required', 'integer'], 'lock_version' => ['nullable', 'integer'],
            'accepted' => ['accepted'], 'signature' => ['required', 'string', 'max:'.SignatureImage::MAX_DATA_URL_LENGTH],
        ];
        if ($kind === 'application') {
            $this->allowJoin($member);
            $rules = [...$rules, ...$this->profileRules(),
                'membership_type' => ['required', Rule::in(array_keys($this->membershipOptions()))],
                'guardian_name' => ['nullable', 'string', 'max:255'],
                'guardian_signature' => ['nullable', 'string', 'max:'.SignatureImage::MAX_DATA_URL_LENGTH],
            ];
        } else {
            abort_unless($member && $member->joined_at && $member->left_at === null, 403);
            $rules = [...$rules, 'iban' => ['required', 'string', 'max:42', new Iban],
                'account_holder_first_name' => ['required', 'string', 'max:255'], 'account_holder_last_name' => ['required', 'string', 'max:255'],
                'account_holder_street' => ['required', 'string', 'max:255'], 'account_holder_postal_code' => ['required', 'string', 'max:20'],
                'account_holder_city' => ['required', 'string', 'max:255'], 'account_holder_country' => ['required', 'string', 'max:255'],
            ];
        }
        $values = $request->validate($rules);
        $signature = $this->signature($values['signature'], 'signature');
        $guardian = null;
        if ($kind === 'application' && CarbonImmutable::parse($values['birth_date'])->age < 18) {
            if (empty($values['guardian_name']) || empty($values['guardian_signature'])) {
                throw ValidationException::withMessages(['guardian_signature' => 'Für Minderjährige sind Name und Unterschrift einer sorgeberechtigten Person erforderlich.']);
            }
            $guardian = $this->signature($values['guardian_signature'], 'guardian_signature');
        }
        $result = DB::transaction(function () use ($request, $member, $email, $kind, $values, $signature, $guardian, $documents, $changes): Member {
            // Serialize public allocations and configuration changes, then lock the member.
            $settings = ClubSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            abort_unless($settings->data['selfservice_enabled'] ?? false, 404);
            if ($settings->version !== (int) $values['version']) {
                throw ValidationException::withMessages(['version' => 'Die Formulartexte wurden geändert. Bitte lade das Formular neu und prüfe den aktuellen Text.']);
            }
            $current = $member ? Member::query()->whereKey($member->id)->lockForUpdate()->firstOrFail() : null;
            if ($current) {
                $this->checkVersion($current, (int) ($values['lock_version'] ?? -1));
                if (DB::table('member_documents')->where('member_id', $current->id)->where('kind', $kind)->exists()) {
                    throw ValidationException::withMessages(['accepted' => 'Dieses Dokument wurde bereits angelegt.']);
                }
            }
            if ($kind === 'application') {
                $this->allowJoin($current);
                $requiresApproval = ($settings->data['membership_activation'] ?? 'immediate') === 'approval';
                $updates = [...Arr::only($values, self::PROFILE), 'membership_type' => $requiresApproval ? 'Kontakt' : $values['membership_type'], 'joined_at' => $requiresApproval ? null : now()->toDateString()];
                if (! $current) {
                    if (Member::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                        throw ValidationException::withMessages(['accepted' => 'Zu dieser Adresse gibt es bereits einen Datensatz. Bitte fordere einen Mitgliederzugang an.']);
                    }
                    $current = Member::query()->create(['member_number' => ((int) Member::query()->max('member_number')) + 1, 'email' => $email, 'first_name' => $values['first_name'], 'last_name' => $values['last_name']]);
                }
                $changes->save($current, $updates);
                if ($requiresApproval) {
                    DB::table('membership_applications')->insert([
                        'member_id' => $current->id, 'membership_type' => $values['membership_type'], 'submitted_at' => now(),
                    ]);
                }
            } else {
                abort_unless($current->joined_at && $current->left_at === null, 403);
                if (empty($settings->data['creditor_id']) || empty($settings->data['name'])) {
                    throw ValidationException::withMessages(['accepted' => 'Der Verein muss zunächst Vereinsname und SEPA-Gläubiger-ID konfigurieren.']);
                }
                $changes->save($current, [
                    ...Arr::only($values, ['account_holder_first_name', 'account_holder_last_name', 'account_holder_street', 'account_holder_postal_code', 'account_holder_city', 'account_holder_country']),
                    'iban' => strtoupper(str_replace(' ', '', $values['iban'])),
                    'mandate_reference' => 'M'.$current->member_number.'-'.strtoupper(bin2hex(random_bytes(6))),
                    'mandate_signed_at' => now()->toDateString(), 'payment_method' => 'SEPA-Lastschrift',
                ]);
            }
            $documents->store($current, $kind, $signature, $guardian, $values['guardian_name'] ?? null, $request);

            return $current;
        }, attempts: 3);
        Access::signIn($request, $email, $result->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => $kind === 'application' ? ($result->joined_at ? 'Dein Beitritt wurde gespeichert. Du kannst jetzt dein SEPA-Mandat anlegen.' : 'Dein Antrag wurde gespeichert und wartet auf Freigabe durch die Verwaltung.') : 'Dein SEPA-Mandat wurde gespeichert.']);

        return redirect('/selfservice');
    }

    public function document(Request $request, string $kind, MemberDocumentStore $documents): HttpResponse
    {
        $member = Access::member($request);
        abort_unless(in_array($kind, ['application', 'sepa'], true), 404);
        $document = DB::table('member_documents')->where('member_id', $member->id)->where('kind', $kind)->first(['contents', 'encrypted', 'content_sha256']);
        abort_unless($document !== null, 404);
        try {
            $record = (array) $document;
            $contents = $documents->read(
                $record['contents'] ?? null,
                (bool) ($record['encrypted'] ?? false),
                is_string($record['content_sha256'] ?? null) ? $record['content_sha256'] : null,
            );
        } catch (\Throwable) {
            abort(422, 'Das hinterlegte Dokument ist beschädigt oder nicht lesbar.');
        }

        return response($contents, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$kind.'-'.$member->member_number.'.pdf"', 'Cache-Control' => 'private, no-store', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function signature(string $value, string $field): string
    {
        try {
            return 'data:image/png;base64,'.base64_encode(SignatureImage::fromDataUrl($value));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
    }

    private function checkVersion(Member $member, int $version): void
    {
        if ($member->lock_version !== $version) {
            throw ValidationException::withMessages(['lock_version' => 'Deine Daten wurden inzwischen geändert. Bitte lade die Seite neu.']);
        }
    }

    private function allowJoin(?Member $member): void
    {
        if ($member) {
            abort_unless($member->membership_type === 'Kontakt' && $member->joined_at === null && $member->deceased_at === null && ! DB::table('membership_applications')->where('member_id', $member->id)->exists(), 403);
        } else {
            abort_unless(ClubSetting::current()->data['public_join_enabled'] ?? false, 403);
        }
    }
}
