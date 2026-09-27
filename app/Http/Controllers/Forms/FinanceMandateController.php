<?php

namespace App\Http\Controllers\Forms;

use App\Configuration\MailConfigurator;
use App\Documents\SignatureImage;
use App\Forms\FinanceMandates;
use App\Http\Controllers\Controller;
use App\Mail\FinanceMandateMail;
use App\Members\MemberReportWriter;
use App\Models\ClubSetting;
use App\Models\FinanceMandate;
use App\Rules\Iban;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class FinanceMandateController extends Controller
{
    public function index(Request $request): Response
    {
        $tab = (string) $request->route('tab', 'overview');
        abort_unless(in_array($tab, ['overview', 'create'], true), 404);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', Rule::in(['all', 'pending', 'signed', 'revoked'])],
            'mandate_type' => ['nullable', Rule::in(['all', 'recurring', 'one_off'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $normalizedFilters = [
            'search' => $search,
            'from' => $filters['from'] ?? '',
            'to' => $filters['to'] ?? '',
            'status' => $filters['status'] ?? 'all',
            'mandate_type' => $filters['mandate_type'] ?? 'all',
        ];
        $mandates = $this->filteredQuery($normalizedFilters)
            ->select(['id', 'mandate_reference', 'debtor_name', 'debtor_email', 'iban', 'mandate_type', 'status', 'signed_at', 'signature_method', 'revoked_at', 'revoked_by_name', 'revocation_reason', 'encrypted_signing_token', 'created_at'])
            ->latest('id')->paginate(20)->withQueryString()
            ->through(fn (FinanceMandate $mandate): array => [
                ...$mandate->toArray(),
                'signing_url' => $mandate->status === 'pending'
                    ? route('forms.mandates.sign', ['token' => $mandate->signingToken()])
                    : null,
            ]);
        $club = ClubSetting::current()->data;

        return Inertia::render('forms/SepaMandates', [
            'activeTab' => $tab, 'mandates' => $mandates, 'search' => $search, 'filters' => $normalizedFilters,
            'creationKey' => (string) Str::uuid(), 'today' => now()->toDateString(),
            'defaultCountry' => $club['country'] ?? 'DE',
            'clubReady' => collect(['name', 'street', 'postal_code', 'city', 'country', 'creditor_id'])->every(fn (string $key): bool => ! empty($club[$key])),
        ]);
    }

    public function report(Request $request): HttpResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', Rule::in(['pending', 'signed', 'revoked'])],
            'mandate_type' => ['nullable', Rule::in(['recurring', 'one_off'])],
        ]);
        $filters['search'] = trim((string) ($filters['search'] ?? ''));
        $query = $this->filteredQuery($filters)
            ->select(['id', 'mandate_reference', 'debtor_name', 'debtor_email', 'iban', 'mandate_type', 'status', 'signed_at', 'revoked_at', 'revocation_reason', 'created_at'])
            ->latest('created_at')->latest('id');
        if ((clone $query)->count() > 5000) {
            throw ValidationException::withMessages(['scope' => 'Der Bericht ist auf 5.000 Mandate begrenzt. Bitte den Zeitraum oder die Filter einschränken.']);
        }
        $mandates = $query->get();
        $settings = ClubSetting::current();
        $club = $settings->data;
        $logo = $settings->logoDataUri();
        $printedAt = now()->setTimezone(config('app.display_timezone'));
        $html = view('forms.mandate-report', compact('mandates', 'filters', 'club', 'logo', 'printedAt'))->render();
        $pdf = MemberReportWriter::pdf($html, true);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="mandatsbuch-'.now()->format('Y-m-d-His').'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request, FinanceMandates $mandates): RedirectResponse
    {
        $request->merge(['iban' => strtoupper(preg_replace('/\s+/', '', (string) $request->input('iban')) ?? ''), 'debtor_country' => strtoupper((string) $request->input('debtor_country'))]);
        $data = $request->validate([
            'creation_key' => ['required', 'uuid'], 'debtor_name' => ['required', 'string', 'max:255'],
            'debtor_street' => ['required', 'string', 'max:255'], 'debtor_postal_code' => ['required', 'string', 'max:20'],
            'debtor_city' => ['required', 'string', 'max:255'], 'debtor_country' => ['required', 'string', 'size:2'],
            'debtor_email' => ['nullable', 'email:rfc', 'max:255'], 'iban' => ['required', 'string', 'max:42', new Iban],
            'mandate_type' => ['required', Rule::in(['recurring', 'one_off'])],
        ]);
        $mandate = $mandates->create($data, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'SEPA-Mandat '.$mandate->mandate_reference.' wurde angelegt. Es ist bis zur Unterschrift nicht verwendbar.']);

        return to_route('forms.mandates.index');
    }

    public function document(Request $request, FinanceMandate $mandate): HttpResponse
    {
        $request->validate(['inline' => ['nullable', 'boolean']]);

        return response($mandate->pdf(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('inline') ? 'inline' : 'attachment').'; filename="'.$mandate->filename().'"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function send(Request $request, FinanceMandate $mandate, MailConfigurator $mailConfigurator): RedirectResponse
    {
        if ($mandate->status === 'revoked') {
            return back()->withErrors(['recipient' => 'Ein widerrufenes Mandat kann nicht mehr versendet werden.']);
        }
        $data = $request->validate(['recipient' => ['required', 'email:rfc', 'max:255']]);
        $mailConfigurator->applyStored();
        try {
            Mail::to($data['recipient'])->send(new FinanceMandateMail($mandate));
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['recipient' => 'Das Mandat konnte nicht versendet werden. Bitte die E-Mail-Konfiguration prüfen.']);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'SEPA-Mandat wurde mit Unterschriftslink versendet.']);

        return back();
    }

    public function markSigned(Request $request, FinanceMandate $mandate, FinanceMandates $mandates): RedirectResponse
    {
        $data = $request->validate(['signed_by_name' => ['required', 'string', 'max:255'], 'signed_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']]);
        $mandates->markPaperSigned($mandate, $request->user(), $data['signed_by_name'], $data['signed_at']);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Das Mandat wurde als unterschrieben gekennzeichnet und ist jetzt verwendbar.']);

        return back();
    }

    public function revoke(Request $request, FinanceMandate $mandate, FinanceMandates $mandates): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $mandates->revoke($mandate, $request->user(), $data['reason']);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Das SEPA-Mandat wurde widerrufen und kann nicht mehr verwendet werden.']);

        return back();
    }

    public function publicShow(string $token): Response
    {
        $mandate = $this->resolveToken($token);
        $settings = ClubSetting::current();

        return Inertia::render('public/SepaMandateSign', [
            'token' => $token,
            'mandate' => $this->publicData($mandate),
            'clubName' => $settings->data['name'] ?? config('app.name'),
            'logoUrl' => ! empty($settings->data['logo_path']) ? route('branding.logo', ['v' => $settings->version]) : null,
        ]);
    }

    public function publicSign(Request $request, string $token, FinanceMandates $mandates): RedirectResponse
    {
        $mandate = $this->resolveToken($token);
        if ($mandate->status !== 'pending') {
            return back();
        }
        $data = $request->validate([
            'signed_by_name' => ['required', 'string', 'max:255'],
            'signature_data' => ['required', 'string', 'max:'.SignatureImage::MAX_DATA_URL_LENGTH],
            'confirmed' => ['accepted'],
        ]);
        $mandates->signDigitally($mandate, $data['signed_by_name'], $data['signature_data']);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Vielen Dank. Das SEPA-Mandat wurde rechtsverbindlich unterzeichnet.']);

        return redirect()->route('forms.mandates.sign', ['token' => $token]);
    }

    private function resolveToken(string $token): FinanceMandate
    {
        abort_unless(strlen($token) === 64, 404);

        return FinanceMandate::query()->where('signing_token_hash', hash('sha256', $token))->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function publicData(FinanceMandate $mandate): array
    {
        return [
            'mandate_reference' => $mandate->mandate_reference, 'debtor_name' => $mandate->debtor_name,
            'iban_masked' => '•••• '.substr($mandate->iban, -4), 'mandate_type' => $mandate->mandate_type,
            'mandate_text' => $mandate->mandate_text, 'status' => $mandate->status,
            'signed_at' => $mandate->signed_at?->toIso8601String(),
            'revoked_at' => $mandate->revoked_at?->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $filters
     * @return Builder<FinanceMandate>
     */
    private function filteredQuery(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return FinanceMandate::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $query->where(function (Builder $query) use ($term): void {
                    $query->where('mandate_reference', 'like', $term)
                        ->orWhere('debtor_name', 'like', $term)
                        ->orWhere('debtor_email', 'like', $term);
                });
            })
            ->when(! empty($filters['from']), fn (Builder $query) => $query->whereDate('created_at', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn (Builder $query) => $query->whereDate('created_at', '<=', $filters['to']))
            ->when(! empty($filters['status']) && $filters['status'] !== 'all', fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(! empty($filters['mandate_type']) && $filters['mandate_type'] !== 'all', fn (Builder $query) => $query->where('mandate_type', $filters['mandate_type']));
    }
}
