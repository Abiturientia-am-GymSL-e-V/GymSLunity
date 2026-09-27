<?php

namespace App\Http\Controllers\SelfService;

use App\Calendar\CalendarAccess;
use App\Configuration\SoftwareModules;
use App\Documents\SignatureImage;
use App\Http\Controllers\Controller;
use App\Mail\MembershipWelcomeMail;
use App\Members\MemberFields;
use App\Members\MemberMandates;
use App\Members\MemberValidation;
use App\Models\ClubSetting;
use App\Models\ContributionAccount;
use App\Models\Member;
use App\Models\MemberFieldDefinition;
use App\Payments\GiroCode;
use App\Rules\Iban;
use App\Security\MemberDocumentStore;
use App\SelfService\Access;
use App\SelfService\Documents;
use App\SelfService\FormTemplates;
use App\SelfService\ProfileChanges;
use App\Support\FormOfAddress;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class PortalController extends Controller
{
    public const PROFILE = ['first_name', 'middle_name', 'last_name', 'gender', 'birth_date', 'mobile_phone', 'street', 'postal_code', 'city', 'country'];

    /** @return array<string, mixed> */
    private function profileRules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'], 'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'], 'gender' => ['nullable', Rule::in(array_keys($this->genderOptions()))],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'mobile_phone' => ['nullable', 'string', 'max:50'], 'street' => ['required', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:20'], 'city' => ['required', 'string', 'max:255'], 'country' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    private function genderOptions(): array
    {
        $field = MemberFieldDefinition::query()->where('key', 'gender')->firstOrFail();

        return MemberFields::descriptor($field)['activeOptions'];
    }

    /** @return array<string, string> */
    private function membershipOptions(): array
    {
        $field = MemberFieldDefinition::query()->where('key', 'membership_type')->firstOrFail();
        $descriptor = MemberFields::descriptor($field);

        return Arr::except($descriptor['activeOptions'], 'Kontakt');
    }

    /** @param array<string, mixed>|null $settings
     * @return array<string, string>
     */
    private function paymentOptions(?array $settings = null): array
    {
        $field = MemberFieldDefinition::query()->where('key', 'payment_method')->firstOrFail();
        $options = MemberFields::descriptor($field)['activeOptions'];
        if (! $this->sepaReady($settings ?? ClubSetting::current()->data)) {
            unset($options['SEPA-Lastschrift']);
        }

        return $options;
    }

    /** @param array<string, mixed> $settings */
    private function sepaReady(array $settings): bool
    {
        foreach (['name', 'creditor_id', 'account_holder', 'iban', 'bic'] as $key) {
            if (! is_string($settings[$key] ?? null) || trim($settings[$key]) === '') {
                return false;
            }
        }

        return true;
    }

    public function index(Request $request, CalendarAccess $calendarAccess, GiroCode $giroCode): Response
    {
        $member = Access::member($request);
        $calendarEnabled = SoftwareModules::enabled('calendar');
        $calendars = $calendarEnabled ? $calendarAccess->forMember($member) : collect();
        $calendarToken = DB::table('member_calendar_tokens')->where('member_id', $member->id)->value('token');
        if ($calendars->isNotEmpty() && ! is_string($calendarToken)) {
            DB::table('member_calendar_tokens')->insertOrIgnore(['member_id' => $member->id, 'token' => bin2hex(random_bytes(24)), 'created_at' => now(), 'updated_at' => now()]);
            $calendarToken = DB::table('member_calendar_tokens')->where('member_id', $member->id)->value('token');
        }

        $profileSections = $this->profileSections($member);
        $visibleKeys = collect($profileSections)->flatMap(fn (array $section): array => array_column($section['fields'], 'key'))->all();
        $memberData = [
            ...Arr::only(MemberFields::snapshot($member), $visibleKeys),
            ...Arr::only($member->attributesToArray(), ['member_number', 'first_name', 'email', 'membership_type', 'payment_method', 'sponsor_contribution', 'joined_at', 'left_at', 'lock_version']),
        ];
        $club = ClubSetting::current()->data;
        $pendingCancellation = DB::table('membership_cancellations')
            ->where('member_id', $member->id)
            ->whereNull('confirmed_at')
            ->whereNull('withdrawn_at')
            ->first(['requested_at']);

        return Inertia::render('selfservice/Portal', [
            'member' => $memberData,
            'profileSections' => $profileSections,
            'documents' => DB::table('member_documents')
                ->where('member_id', $member->id)
                ->where(function ($query) use ($member): void {
                    $query->where('kind', 'application');
                    if ($member->payment_method === 'SEPA-Lastschrift' && $member->mandate_reference) {
                        $query->orWhere(fn ($mandate) => $mandate
                            ->where('kind', 'sepa')
                            ->whereNull('revoked_at')
                            ->where('mandate_reference', $member->mandate_reference));
                    }
                })
                ->distinct()
                ->pluck('kind'),
            'canJoin' => $this->canJoin($member),
            'isActiveMember' => $this->isActiveMember($member),
            'pendingApplication' => DB::table('membership_applications')->where('member_id', $member->id)->whereNull('approved_at')->first(['membership_type', 'submitted_at']),
            'canRequestCancellation' => $this->isActiveMember($member) && $member->left_at === null && $pendingCancellation === null,
            'pendingCancellation' => $pendingCancellation,
            'calendarEnabled' => $calendarEnabled,
            'bookingsEnabled' => SoftwareModules::enabled('bookings'),
            'calendarSubscription' => $calendars->isEmpty() ? null : [
                'url' => route('calendar.feed.member', $calendarToken),
                'calendars' => $calendars->map(fn ($calendar): array => ['name' => $calendar->name, 'color' => $calendar->color])->values(),
            ],
            'contributionAccount' => SoftwareModules::enabled('payments') ? $this->contributionAccount($member, $club, $giroCode) : null,
        ]);
    }

    public function update(Request $request, ProfileChanges $changes, MemberMandates $mandates): RedirectResponse
    {
        $member = Access::member($request);
        $definitions = $this->editableDefinitions($member);
        $keys = $definitions->pluck('key')->all();
        $input = $request->only([...$keys, 'lock_version']);
        foreach ($definitions->where('type', 'decimal') as $definition) {
            if (is_string($input[$definition->key] ?? null)) {
                $input[$definition->key] = str_replace(',', '.', $input[$definition->key]);
            }
        }
        $rules = Arr::only(MemberValidation::rules($member), $keys);
        $rules['lock_version'] = ['required', 'integer', 'min:0'];
        if (isset($rules['membership_type'])) {
            $rules['membership_type'] = ['bail', 'sometimes', 'required', Rule::in(array_unique([...array_keys($this->membershipOptions()), $member->membership_type]))];
        }
        if (isset($rules['payment_method'])) {
            $rules['payment_method'] = ['bail', 'sometimes', 'required', Rule::in(array_unique([...array_keys($this->paymentOptions()), $member->payment_method]))];
        }
        $values = Validator::make($input, $rules, MemberValidation::messages(), MemberValidation::attributes())->validate();
        if ($member->payment_method !== 'SEPA-Lastschrift'
            && ($values['payment_method'] ?? null) === 'SEPA-Lastschrift') {
            throw ValidationException::withMessages([
                'payment_method' => FormOfAddress::choose('Bitte richte die SEPA-Lastschrift über ein neues, unterschriebenes Mandat ein.', 'Bitte richten Sie die SEPA-Lastschrift über ein neues, unterschriebenes Mandat ein.'),
            ]);
        }
        $membershipType = (string) ($values['membership_type'] ?? $member->membership_type);
        if ($this->isSponsorMembership($membershipType)) {
            if (! array_key_exists('sponsor_contribution', $values) || (float) $values['sponsor_contribution'] <= 0) {
                throw ValidationException::withMessages(['sponsor_contribution' => FormOfAddress::choose('Bitte lege für die Fördermitgliedschaft einen Förderbetrag fest.', 'Bitte legen Sie für die Fördermitgliedschaft einen Förderbetrag fest.')]);
            }
        } elseif (in_array('sponsor_contribution', $keys, true)) {
            $values['sponsor_contribution'] = null;
        }
        $changed = DB::transaction(function () use ($member, $values, $changes, $keys, $mandates): bool {
            $current = Member::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            $this->checkVersion($current, (int) $values['lock_version']);
            $updates = Arr::only($values, $keys);
            if ($current->payment_method === 'SEPA-Lastschrift'
                && array_key_exists('payment_method', $updates)
                && $updates['payment_method'] !== 'SEPA-Lastschrift') {
                $mandates->revoke($current, 'Zahlungsart geändert zu '.($updates['payment_method'] ?: 'nicht hinterlegt'));
                $updates = [...$updates, ...$mandates->clearedDetails()];
            }
            MemberValidation::validateDates(array_replace(MemberFields::snapshot($current), $updates));

            return $changes->save($current, $updates);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => $changed ? FormOfAddress::choose('Deine Änderungen wurden gespeichert.', 'Ihre Änderungen wurden gespeichert.') : 'Es waren keine Änderungen zu speichern.']);

        return back();
    }

    public function cancelMembership(Request $request): RedirectResponse
    {
        $member = Access::member($request);
        $values = $request->validate([
            'lock_version' => ['required', 'integer', 'min:0'],
        ]);
        DB::transaction(function () use ($member, $values): void {
            $current = Member::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->isActiveMember($current) && $current->left_at === null && $current->deceased_at === null, 403);
            $this->checkVersion($current, (int) $values['lock_version']);
            if (DB::table('membership_cancellations')->where('member_id', $current->id)->whereNull('confirmed_at')->whereNull('withdrawn_at')->exists()) {
                throw ValidationException::withMessages(['cancellation' => FormOfAddress::choose('Deine Kündigung wurde bereits an den Vorstand übermittelt.', 'Ihre Kündigung wurde bereits an den Vorstand übermittelt.')]);
            }
            DB::table('membership_cancellations')->insert([
                'member_id' => $current->id,
                'requested_at' => now(),
            ]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => FormOfAddress::choose('Deine Kündigung wurde an den Vorstand übermittelt.', 'Ihre Kündigung wurde an den Vorstand übermittelt.')]);

        return back();
    }

    public function withdrawCancellation(Request $request, ProfileChanges $changes): RedirectResponse
    {
        $member = Access::member($request);
        $values = $request->validate([
            'lock_version' => ['required', 'integer', 'min:0'],
        ]);
        DB::transaction(function () use ($member, $values, $changes): void {
            $current = Member::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            $this->checkVersion($current, (int) $values['lock_version']);
            $cancellation = DB::table('membership_cancellations')
                ->where('member_id', $current->id)
                ->whereNull('withdrawn_at')
                ->where(function ($query): void {
                    $query->whereNull('confirmed_at')
                        ->orWhere(fn ($confirmed) => $confirmed
                            ->whereNotNull('confirmed_at')
                            ->whereDate('exit_date', '>', now()->toDateString()));
                })
                ->latest('id')
                ->lockForUpdate()
                ->first(['id', 'confirmed_at', 'exit_date']);
            if ($cancellation === null) {
                throw ValidationException::withMessages([
                    'cancellation' => 'Es liegt keine aktive Kündigung vor.',
                ]);
            }
            if ($cancellation->confirmed_at !== null) {
                abort_unless(
                    $current->left_at?->isFuture()
                    && $current->left_at->format('Y-m-d') === $cancellation->exit_date,
                    409,
                    'Das Austrittsdatum wurde inzwischen geändert.',
                );
                $changes->save($current, ['left_at' => null]);
            }
            DB::table('membership_cancellations')
                ->where('id', $cancellation->id)
                ->update(['withdrawn_at' => now()]);
        }, attempts: 3);
        Inertia::flash('toast', ['type' => 'success', 'message' => FormOfAddress::choose('Deine Kündigung wurde zurückgenommen.', 'Ihre Kündigung wurde zurückgenommen.')]);

        return back();
    }

    public function form(Request $request, string $kind): Response
    {
        abort_unless(in_array($kind, ['application', 'sepa'], true), 404);
        $email = Access::email($request);
        $member = $request->session()->get('selfservice.member_id') ? Access::member($request) : null;
        if ($kind === 'sepa') {
            abort_unless($member && $this->isActiveMember($member), 403);
        } else {
            $this->allowJoin($member);
        }

        return Inertia::render('selfservice/Form', [
            'kind' => $kind, 'email' => $email,
            'requiresApproval' => (ClubSetting::current()->data['membership_activation'] ?? 'immediate') === 'approval',
            'member' => $member ? Arr::only($member->attributesToArray(), [
                ...self::PROFILE, ...MemberMandates::DETAILS, 'sponsor_contribution', 'lock_version',
            ]) : null,
            'hasActiveMandate' => (bool) ($member
                && $member->payment_method === 'SEPA-Lastschrift'
                && $member->mandate_reference
                && DB::table('member_documents')
                    ->where('member_id', $member->id)
                    ->where('kind', 'sepa')
                    ->whereNull('revoked_at')
                    ->where('mandate_reference', $member->mandate_reference)
                    ->exists()),
            'texts' => FormTemplates::rendered(), 'version' => ClubSetting::current()->version,
            'membershipOptions' => $this->membershipOptions(),
            'paymentOptions' => $this->paymentOptions(),
            'genderOptions' => $this->genderOptions(),
        ]);
    }

    public function submit(Request $request, string $kind, Documents $documents, ProfileChanges $changes, MemberMandates $mandates): RedirectResponse
    {
        abort_unless(in_array($kind, ['application', 'sepa'], true), 404);
        if (is_string($request->input('iban'))) {
            $request->merge(['iban' => strtoupper(preg_replace('/\s+/', '', $request->input('iban')) ?? '')]);
        }
        $email = Access::email($request);
        $member = $request->session()->get('selfservice.member_id') ? Access::member($request) : null;
        $rules = [
            'version' => ['required', 'integer'], 'lock_version' => ['nullable', 'integer'],
            'accepted' => ['accepted'], 'signature' => ['required', 'string', 'max:'.SignatureImage::MAX_DATA_URL_LENGTH],
        ];
        if ($kind === 'application') {
            $this->allowJoin($member);
            $paymentOptions = $this->paymentOptions();
            $sepaSelected = $request->input('payment_method') === 'SEPA-Lastschrift';
            $sponsorSelected = $this->isSponsorMembership((string) $request->input('membership_type'));
            $rules = [...$rules, ...$this->profileRules(),
                'membership_type' => ['required', Rule::in(array_keys($this->membershipOptions()))],
                'payment_method' => ['required', Rule::in(array_keys($paymentOptions))],
                'sponsor_contribution' => [$sponsorSelected ? 'required' : 'nullable', 'numeric', 'decimal:0,2', 'between:0.01,999.99'],
                'guardian_name' => ['nullable', 'string', 'max:255'],
                'guardian_signature' => ['nullable', 'string', 'max:'.SignatureImage::MAX_DATA_URL_LENGTH],
                'mandate_accepted' => [$sepaSelected ? 'accepted' : 'nullable'],
                'mandate_signature' => [$sepaSelected ? 'required' : 'nullable', 'string', 'max:'.SignatureImage::MAX_DATA_URL_LENGTH],
                'iban' => [$sepaSelected ? 'required' : 'nullable', 'string', 'max:42', new Iban],
                'account_holder_first_name' => [$sepaSelected ? 'required' : 'nullable', 'string', 'max:255'],
                'account_holder_last_name' => [$sepaSelected ? 'required' : 'nullable', 'string', 'max:255'],
                'account_holder_street' => [$sepaSelected ? 'required' : 'nullable', 'string', 'max:255'],
                'account_holder_postal_code' => [$sepaSelected ? 'required' : 'nullable', 'string', 'max:20'],
                'account_holder_city' => [$sepaSelected ? 'required' : 'nullable', 'string', 'max:255'],
                'account_holder_country' => [$sepaSelected ? 'required' : 'nullable', 'string', 'max:255'],
            ];
        } else {
            abort_unless($member && $this->isActiveMember($member), 403);
            $rules = [...$rules, 'iban' => ['required', 'string', 'max:42', new Iban],
                'account_holder_first_name' => ['required', 'string', 'max:255'], 'account_holder_last_name' => ['required', 'string', 'max:255'],
                'account_holder_street' => ['required', 'string', 'max:255'], 'account_holder_postal_code' => ['required', 'string', 'max:20'],
                'account_holder_city' => ['required', 'string', 'max:255'], 'account_holder_country' => ['required', 'string', 'max:255'],
            ];
        }
        $values = $request->validate($rules);
        $signature = $this->signature($values['signature'], 'signature');
        $mandateSignature = $kind === 'application' && ($values['payment_method'] ?? null) === 'SEPA-Lastschrift'
            ? $this->signature($values['mandate_signature'], 'mandate_signature')
            : null;
        $guardian = null;
        if ($kind === 'application' && CarbonImmutable::parse($values['birth_date'])->age < 18) {
            if (empty($values['guardian_name']) || empty($values['guardian_signature'])) {
                throw ValidationException::withMessages(['guardian_signature' => 'Für Minderjährige sind Name und Unterschrift einer sorgeberechtigten Person erforderlich.']);
            }
            $guardian = $this->signature($values['guardian_signature'], 'guardian_signature');
        }
        $result = DB::transaction(function () use ($request, $member, $email, $kind, $values, $signature, $mandateSignature, $guardian, $documents, $changes, $mandates): array {
            // Serialize public allocations and configuration changes, then lock the member.
            $settings = ClubSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            abort_unless($settings->data['selfservice_enabled'] ?? false, 404);
            if ($settings->version !== (int) $values['version']) {
                throw ValidationException::withMessages(['version' => FormOfAddress::choose('Die Formulartexte wurden geändert. Bitte lade das Formular neu und prüfe den aktuellen Text.', 'Die Formulartexte wurden geändert. Bitte laden Sie das Formular neu und prüfen Sie den aktuellen Text.')]);
            }
            $current = $member ? Member::query()->whereKey($member->id)->lockForUpdate()->firstOrFail() : null;
            if ($current) {
                $this->checkVersion($current, (int) ($values['lock_version'] ?? -1));
            }
            if ($kind === 'application') {
                $this->allowJoin($current);
                if (! array_key_exists($values['payment_method'], $this->paymentOptions($settings->data))) {
                    throw ValidationException::withMessages(['payment_method' => FormOfAddress::choose('Diese Zahlungsart ist nicht mehr verfügbar. Bitte lade das Formular neu.', 'Diese Zahlungsart ist nicht mehr verfügbar. Bitte laden Sie das Formular neu.')]);
                }
                $requiresApproval = ($settings->data['membership_activation'] ?? 'immediate') === 'approval';
                $updates = [
                    ...Arr::only($values, self::PROFILE),
                    'payment_method' => $values['payment_method'],
                    'sponsor_contribution' => $this->isSponsorMembership($values['membership_type']) ? number_format((float) $values['sponsor_contribution'], 2, '.', '') : null,
                    'membership_type' => $requiresApproval ? 'Kontakt' : $values['membership_type'],
                    'joined_at' => $requiresApproval ? null : now()->toDateString(),
                    'left_at' => null,
                ];
                if (! $current) {
                    if (Member::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                        throw ValidationException::withMessages(['accepted' => FormOfAddress::choose('Zu dieser Adresse gibt es bereits einen Datensatz. Bitte fordere einen Mitgliederzugang an.', 'Zu dieser Adresse gibt es bereits einen Datensatz. Bitte fordern Sie einen Mitgliederzugang an.')]);
                    }
                    $current = Member::query()->create(['member_number' => ((int) Member::query()->max('member_number')) + 1, 'email' => $email, 'first_name' => $values['first_name'], 'last_name' => $values['last_name']]);
                }
                if ($current->payment_method === 'SEPA-Lastschrift') {
                    $mandates->revoke($current, 'Durch einen neuen Mitgliedsantrag ersetzt');
                    $updates = [...$updates, ...$mandates->clearedDetails()];
                }
                $changes->save($current, $updates);
                if ($requiresApproval) {
                    DB::table('membership_applications')->updateOrInsert(
                        ['member_id' => $current->id],
                        [
                            'membership_type' => $values['membership_type'],
                            'submitted_at' => now(),
                            'approved_at' => null,
                            'approved_by' => null,
                            'approved_by_name' => null,
                        ],
                    );
                }
                if ($values['payment_method'] === 'SEPA-Lastschrift') {
                    $changes->save($current, [
                        ...Arr::only($values, ['account_holder_first_name', 'account_holder_last_name', 'account_holder_street', 'account_holder_postal_code', 'account_holder_city', 'account_holder_country']),
                        'iban' => $values['iban'],
                        'mandate_reference' => 'M'.$current->member_number.'-'.strtoupper(bin2hex(random_bytes(6))),
                        'mandate_signed_at' => now()->toDateString(),
                    ]);
                }
            } else {
                abort_unless($this->isActiveMember($current), 403);
                if (empty($settings->data['creditor_id']) || empty($settings->data['name'])) {
                    throw ValidationException::withMessages(['accepted' => 'Der Verein muss zunächst Vereinsname und SEPA-Gläubiger-ID konfigurieren.']);
                }
                $mandates->revoke($current, 'Durch ein neues SEPA-Mandat ersetzt');
                $changes->save($current, [
                    ...Arr::only($values, ['account_holder_first_name', 'account_holder_last_name', 'account_holder_street', 'account_holder_postal_code', 'account_holder_city', 'account_holder_country']),
                    'iban' => $values['iban'],
                    'mandate_reference' => 'M'.$current->member_number.'-'.strtoupper(bin2hex(random_bytes(6))),
                    'mandate_signed_at' => now()->toDateString(), 'payment_method' => 'SEPA-Lastschrift',
                ]);
            }
            $applicationPdf = $documents->store($current, $kind, $signature, $guardian, $values['guardian_name'] ?? null, $request);
            if ($kind === 'application' && $mandateSignature !== null) {
                $documents->store($current, 'sepa', $mandateSignature, null, null, $request);
            }

            return ['member' => $current, 'applicationPdf' => $kind === 'application' ? $applicationPdf : null];
        }, attempts: 3);
        /** @var Member $savedMember */
        $savedMember = $result['member'];
        if ($kind === 'application') {
            $welcomeMail = new MembershipWelcomeMail($savedMember->member_number, $result['applicationPdf']);
            // The application is already committed; delivery failures must not undo it.
            \Illuminate\Support\defer(function () use ($email, $welcomeMail): void {
                try {
                    Mail::to($email)->send($welcomeMail);
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });
        }
        Access::signIn($request, $email, $savedMember->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => $kind === 'application'
            ? ($savedMember->joined_at
                ? FormOfAddress::choose('Dein Beitritt wurde gespeichert. Du kannst jetzt dein SEPA-Mandat anlegen.', 'Ihr Beitritt wurde gespeichert. Sie können jetzt Ihr SEPA-Mandat anlegen.')
                : FormOfAddress::choose('Dein Antrag wurde gespeichert und wartet auf Freigabe durch den Vorstand.', 'Ihr Antrag wurde gespeichert und wartet auf Freigabe durch den Vorstand.'))
            : FormOfAddress::choose('Dein SEPA-Mandat wurde gespeichert.', 'Ihr SEPA-Mandat wurde gespeichert.')]);

        return redirect('/selfservice');
    }

    public function document(Request $request, string $kind, MemberDocumentStore $documents): HttpResponse
    {
        $member = Access::member($request);
        abort_unless(in_array($kind, ['application', 'sepa'], true), 404);
        if ($kind === 'sepa') {
            abort_unless($member->payment_method === 'SEPA-Lastschrift', 404);
        }
        $document = DB::table('member_documents')
            ->where('member_id', $member->id)
            ->where('kind', $kind)
            ->when($kind === 'sepa', fn ($query) => $query
                ->whereNull('revoked_at')
                ->where('mandate_reference', $member->mandate_reference))
            ->latest('id')
            ->first(['contents', 'encrypted', 'content_sha256']);
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
            throw ValidationException::withMessages(['lock_version' => FormOfAddress::choose('Deine Daten wurden inzwischen geändert. Bitte lade die Seite neu.', 'Ihre Daten wurden inzwischen geändert. Bitte laden Sie die Seite neu.')]);
        }
    }

    private function allowJoin(?Member $member): void
    {
        if ($member) {
            abort_unless($this->canJoin($member), 403);
        } else {
            abort_unless(ClubSetting::current()->data['public_join_enabled'] ?? false, 403);
        }
    }

    private function canJoin(Member $member): bool
    {
        if ($member->deceased_at !== null
            || DB::table('membership_applications')->where('member_id', $member->id)->whereNull('approved_at')->exists()) {
            return false;
        }

        return ($member->membership_type === 'Kontakt' && $member->joined_at === null)
            || ($member->joined_at !== null
                && $member->left_at !== null
                && ! $member->left_at->isFuture());
    }

    private function isActiveMember(Member $member): bool
    {
        return $member->joined_at !== null
            && $member->deceased_at === null
            && ($member->left_at === null || $member->left_at->isFuture());
    }

    private function isSponsorMembership(string $membershipType): bool
    {
        return str_contains(mb_strtolower($membershipType), 'förder');
    }

    /** @return Collection<int, MemberFieldDefinition> */
    private function editableDefinitions(Member $member): Collection
    {
        return MemberFieldDefinition::query()
            ->where('is_active', true)
            ->where('selfservice_visible', true)
            ->where('selfservice_editable', true)
            ->whereNotIn('key', MemberFields::SELFSERVICE_PROTECTED)
            ->when(! $this->isActiveMember($member), fn ($query) => $query->whereNotIn('key', ['membership_type', 'sponsor_contribution', 'payment_method']))
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /** @return list<array{key: string, title: string, fields: list<array<string, mixed>>}> */
    private function profileSections(Member $member): array
    {
        $editableKeys = $this->editableDefinitions($member)->pluck('key')->all();

        return array_values(collect(MemberFields::selfserviceSections($member))
            ->map(fn (array $section): array => [
                ...$section,
                'fields' => array_map(
                    function (array $field) use ($member, $editableKeys): array {
                        $field['readOnly'] = $field['readOnly'] || ! in_array($field['key'], $editableKeys, true);
                        if ($field['key'] === 'payment_method' && $member->payment_method !== 'SEPA-Lastschrift') {
                            $field['activeOptions'] = Arr::except($field['activeOptions'], 'SEPA-Lastschrift');
                        }

                        return $field;
                    },
                    $section['fields'],
                ),
            ])
            ->filter(fn (array $section): bool => $section['fields'] !== [])
            ->values()
            ->all());
    }

    /** @param array<string, mixed> $club
     * @return array<string, mixed>
     */
    private function contributionAccount(Member $member, array $club, GiroCode $giroCode): array
    {
        $account = ContributionAccount::query()->firstOrCreate(['member_id' => $member->id], ['balance_cents' => 0]);
        $giro = null;
        if ($account->balance_cents > 0 && $member->payment_method === 'Überweisung'
            && is_string($club['account_holder'] ?? null) && trim($club['account_holder']) !== ''
            && is_string($club['iban'] ?? null) && trim($club['iban']) !== '') {
            $giro = $giroCode->create(
                $account->balance_cents,
                $club['account_holder'],
                $club['iban'],
                is_string($club['bic'] ?? null) ? $club['bic'] : null,
                $member->member_number,
            );
        }

        return [
            'balance_cents' => $account->balance_cents,
            'giroCode' => $giro,
            'transactions' => $account->transactions()->latest('booking_date')->latest('id')->limit(25)->get()
                ->map(fn ($entry): array => [
                    'id' => $entry->id,
                    'amount_cents' => $entry->amount_cents,
                    'booking_date' => $entry->booking_date->format('Y-m-d'),
                    'description' => $entry->description,
                    'reference' => $entry->reference,
                ]),
        ];
    }
}
