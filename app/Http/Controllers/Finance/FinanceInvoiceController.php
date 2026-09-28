<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Configuration\MailConfigurator;
use App\Finance\CancelFinanceInvoice;
use App\Finance\IssueFinanceInvoice;
use App\Http\Controllers\Controller;
use App\Mail\FinanceInvoiceMail;
use App\Members\MemberReportWriter;
use App\Models\ClubSetting;
use App\Models\FinanceInvoice;
use App\Models\FinanceMandate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class FinanceInvoiceController extends Controller
{
    public function index(Request $request, CancelFinanceInvoice $cancel): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['all', 'open', 'paid', 'cancelled'])],
            'document_type' => ['nullable', Rule::in(['all', 'invoice', 'cancellation'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $search = trim($filters['search'] ?? '');
        $status = $filters['status'] ?? 'all';
        $documentType = $filters['document_type'] ?? 'all';
        $normalizedFilters = [
            'search' => $search,
            'status' => $status,
            'document_type' => $documentType,
            'from' => $filters['from'] ?? '',
            'to' => $filters['to'] ?? '',
        ];
        $query = $this->filteredQuery($normalizedFilters)
            ->select(['id', 'invoice_number', 'document_type', 'original_invoice_id', 'recipient_name', 'recipient_email', 'issue_date', 'due_date', 'payment_method', 'currency', 'total_cents', 'status', 'paid_at', 'cancellation_reason', 'snapshot', 'created_at'])
            ->with([
                'originalInvoice:id,invoice_number',
                'cancellations:id,original_invoice_id,snapshot',
            ]);
        $invoices = $query->latest('id')->paginate(20)->withQueryString();
        $invoices->through(function (FinanceInvoice $invoice) use ($cancel): array {
            $cancellableItems = $cancel->cancellableItems($invoice);
            $itemCount = is_array($invoice->snapshot['items'] ?? null) ? count($invoice->snapshot['items']) : 0;

            return [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'document_type' => $invoice->document_type,
                'original_invoice_id' => $invoice->original_invoice_id,
                'original_invoice_number' => $invoice->originalInvoice?->invoice_number,
                'recipient_name' => $invoice->recipient_name,
                'recipient_email' => $invoice->recipient_email,
                'issue_date' => $invoice->issue_date->toDateString(),
                'due_date' => $invoice->due_date->toDateString(),
                'payment_method' => $invoice->payment_method,
                'currency' => $invoice->currency,
                'total_cents' => $invoice->total_cents,
                'status' => $invoice->status,
                'paid_at' => $invoice->paid_at?->toIso8601String(),
                'cancellation_reason' => $invoice->cancellation_reason,
                'cancellable_items' => $cancellableItems,
                'partially_cancelled' => $cancellableItems !== [] && count($cancellableItems) < $itemCount,
            ];
        });
        $openInvoices = FinanceInvoice::query()
            ->where('document_type', 'invoice')
            ->where('status', 'open')
            ->withSum('cancellations', 'total_cents')
            ->get(['id', 'total_cents']);

        return Inertia::render('Finance', [
            'invoices' => $invoices,
            'filters' => $normalizedFilters,
            'summary' => [
                'count' => FinanceInvoice::query()->where('document_type', 'invoice')->count(),
                'open_count' => $openInvoices->filter(fn (FinanceInvoice $invoice): bool => $invoice->total_cents > (int) $invoice->cancellations_sum_total_cents)->count(),
                'open_cents' => (int) $openInvoices->sum(fn (FinanceInvoice $invoice): int => max(0, $invoice->total_cents - (int) $invoice->cancellations_sum_total_cents)),
                'paid_cents' => (int) FinanceInvoice::query()->where('document_type', 'invoice')->whereNotNull('paid_at')->sum('total_cents'),
            ],
        ]);
    }

    public function report(Request $request): HttpResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['open', 'paid', 'cancelled'])],
            'document_type' => ['nullable', Rule::in(['invoice', 'cancellation'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $filters['search'] = trim($filters['search'] ?? '');
        $query = $this->filteredQuery($filters)
            ->select(['id', 'invoice_number', 'document_type', 'recipient_name', 'recipient_email', 'issue_date', 'due_date', 'payment_method', 'currency', 'total_cents', 'status'])
            ->latest('issue_date')->latest('id');
        if ((clone $query)->count() > 5000) {
            throw ValidationException::withMessages(['scope' => 'Der Bericht ist auf 5.000 Belege begrenzt. Bitte den Zeitraum oder die Filter einschränken.']);
        }
        $invoices = $query->get();
        $settings = ClubSetting::current();
        $club = $settings->data;
        $logo = $settings->logoDataUri();
        $printedAt = now()->setTimezone(config('app.display_timezone'));
        $html = view('finance.invoice-report', compact('invoices', 'filters', 'club', 'logo', 'printedAt'))->render();
        $pdf = MemberReportWriter::pdf($html, true);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="rechnungsbuch-'.now()->format('Y-m-d-His').'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function create(): Response
    {
        $club = ClubSetting::current()->data;
        $requiredClubFields = [
            'name' => 'Vereinsname',
            'street' => 'Straße und Hausnummer',
            'postal_code' => 'Postleitzahl',
            'city' => 'Ort',
            'country' => 'Land',
            'email' => 'E-Mail-Adresse',
            'phone' => 'Telefonnummer',
        ];
        $missingClubFields = collect($requiredClubFields)
            ->filter(fn (string $label, string $key): bool => empty($club[$key]))
            ->values()
            ->all();
        if (empty($club['tax_number']) && empty($club['vat_id'])) {
            $missingClubFields[] = 'Steuernummer oder USt-IdNr.';
        }

        return Inertia::render('finance/CreateInvoice', [
            'creationKey' => (string) Str::uuid(),
            'today' => now()->toDateString(),
            'defaultDueDate' => now()->addDays(14)->toDateString(),
            'defaultCountry' => $club['country'] ?? 'DE',
            'paymentReadiness' => [
                'bank_transfer' => ! empty($club['iban']) && ! empty($club['account_holder']),
                'sepa_direct_debit' => ! empty($club['iban']) && ! empty($club['bic']) && ! empty($club['creditor_id']),
            ],
            'smallBusinessRegulationEnabled' => (bool) ($club['small_business_regulation_enabled'] ?? false),
            'smallBusinessNotice' => IssueFinanceInvoice::SMALL_BUSINESS_NOTICE,
            'financeMandates' => FinanceMandate::query()->where('status', 'signed')
                ->where(fn ($query) => $query->where('mandate_type', 'recurring')->orWhereNotExists(fn ($subquery) => $subquery
                    ->selectRaw('1')->from('finance_invoices')->whereColumn('finance_invoices.finance_mandate_id', 'finance_mandates.id')))
                ->latest('signed_at')->get([
                    'id', 'mandate_reference', 'debtor_name', 'debtor_email', 'iban', 'mandate_type', 'signed_at',
                ])->map(fn (FinanceMandate $mandate): array => [
                    'id' => $mandate->id, 'mandate_reference' => $mandate->mandate_reference,
                    'debtor_name' => $mandate->debtor_name, 'debtor_email' => $mandate->debtor_email,
                    'iban' => $mandate->iban, 'mandate_type' => $mandate->mandate_type,
                    'signed_at' => $mandate->signed_at?->toDateString(),
                ]),
            'clubReadiness' => [
                'ready' => $missingClubFields === [],
                'missing' => $missingClubFields,
            ],
        ]);
    }

    public function store(Request $request, IssueFinanceInvoice $issue): RedirectResponse
    {
        if ($request->input('payment_method') === 'sepa_direct_debit') {
            $club = ClubSetting::current()->data;
            if (empty($club['iban']) || empty($club['bic']) || empty($club['creditor_id'])) {
                throw ValidationException::withMessages(['payment_method' => 'SEPA-Lastschrift ist erst mit IBAN, BIC und Gläubiger-ID in der Vereinskonfiguration verfügbar.']);
            }
        }
        $request->merge([
            'debtor_iban' => strtoupper(preg_replace('/\s+/', '', (string) $request->input('debtor_iban')) ?? ''),
            'recipient_country' => strtoupper((string) $request->input('recipient_country')),
        ]);
        $data = $request->validate([
            'creation_key' => ['required', 'uuid'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'recipient_street' => ['required', 'string', 'max:255'],
            'recipient_postal_code' => ['required', 'string', 'max:20'],
            'recipient_city' => ['required', 'string', 'max:255'],
            'recipient_country' => ['required', 'string', 'size:2', 'regex:/\A[A-Z]{2}\z/'],
            'recipient_email' => ['required', 'email:rfc', 'max:255'],
            'buyer_reference' => ['required', 'string', 'max:100'],
            'issue_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:today'],
            'service_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
            'currency' => ['required', Rule::in(['EUR'])],
            'payment_method' => ['required', Rule::in(['bank_transfer', 'sepa_direct_debit', 'cash', 'card', 'other'])],
            'finance_mandate_id' => [Rule::requiredIf($request->input('payment_method') === 'sepa_direct_debit'), 'nullable', 'integer', Rule::exists('finance_mandates', 'id')],
            'debtor_iban' => ['nullable', 'string', 'max:42'],
            'mandate_reference' => ['nullable', 'string', 'max:35'],
            'mandate_signed_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:issue_date'],
            'mandate_type' => ['nullable', Rule::in(['recurring', 'one_off'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.quantity' => ['required', 'string', 'regex:/\A\d{1,6}(?:[.,]\d{1,3})?\z/', function ($attribute, $value, $fail): void {
                if (is_string($value) && (float) str_replace(',', '.', $value) <= 0) {
                    $fail('Die Menge muss größer als null sein.');
                }
            }],
            'items.*.unit_code' => ['required', Rule::in(['C62', 'HUR', 'DAY'])],
            'items.*.price_mode' => ['required', Rule::in(['net', 'gross'])],
            'items.*.unit_price' => ['required', 'string', 'regex:/\A\d{1,7}(?:[.,]\d{1,2})?\z/'],
            'items.*.vat_rate' => ['required', Rule::in([0, 7, 19])],
            'items.*.tax_exemption_reason' => ['nullable', 'string', 'max:500'],
        ]);
        if ($data['payment_method'] === 'sepa_direct_debit') {
            $mandate = FinanceMandate::query()
                ->whereKey($request->integer('finance_mandate_id'))
                ->firstOrFail();
            if ($mandate->status !== 'signed' || $mandate->signed_at === null) {
                throw ValidationException::withMessages(['finance_mandate_id' => 'Das ausgewählte Mandat ist noch nicht unterschrieben und daher nicht verwendbar.']);
            }
            if ($mandate->mandate_type === 'one_off' && FinanceInvoice::query()->where('finance_mandate_id', $mandate->id)->exists()) {
                throw ValidationException::withMessages(['finance_mandate_id' => 'Dieses einmalige Mandat wurde bereits für eine Rechnung verwendet.']);
            }
            $data['debtor_iban'] = $mandate->iban;
            $data['mandate_reference'] = $mandate->mandate_reference;
            $data['mandate_signed_at'] = $mandate->signed_at->toDateString();
            $data['mandate_type'] = $mandate->mandate_type;
        }
        $smallBusinessRegulation = (bool) (ClubSetting::current()->data['small_business_regulation_enabled'] ?? false);
        foreach ($data['items'] as $index => $item) {
            if (! $smallBusinessRegulation && (int) $item['vat_rate'] === 0 && trim((string) ($item['tax_exemption_reason'] ?? '')) === '') {
                throw ValidationException::withMessages(["items.$index.tax_exemption_reason" => 'Für eine steuerbefreite Position ist der Befreiungsgrund erforderlich.']);
            }
        }
        $data['notes'] ??= '';
        $invoice = $issue->handle($data, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rechnung '.$invoice->invoice_number.' wurde erstellt und archiviert.']);

        return redirect()->route('finance.invoices.index');
    }

    public function document(Request $request, FinanceInvoice $invoice, string $format): HttpResponse
    {
        abort_unless(in_array($format, ['pdf', 'xrechnung'], true), 404);
        $request->validate(['inline' => ['nullable', 'boolean']]);
        $contents = $format === 'pdf' ? $invoice->pdf() : $invoice->xrechnung();
        $inline = $format === 'pdf' && $request->boolean('inline');

        return response($contents, 200, [
            'Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/xml; charset=UTF-8',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$invoice->filename($format).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function send(Request $request, FinanceInvoice $invoice, MailConfigurator $mailConfigurator): RedirectResponse
    {
        $data = $request->validate(['recipient' => ['required', 'email:rfc', 'max:255']]);
        $mailConfigurator->applyStored();
        try {
            Mail::to($data['recipient'])->send(new FinanceInvoiceMail($invoice));
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['recipient' => 'Die Rechnung konnte nicht versendet werden. Bitte die E-Mail-Konfiguration prüfen und den Versand erneut versuchen.']);
        }
        DB::table('finance_invoice_deliveries')->insert([
            'finance_invoice_id' => $invoice->id,
            'recipient' => $data['recipient'],
            'sent_by' => $request->user()->id,
            'sent_by_name' => $request->user()->name,
            'created_at' => now(),
        ]);
        $label = $invoice->document_type === 'cancellation' ? 'Stornorechnung' : 'Rechnung';
        Inertia::flash('toast', ['type' => 'success', 'message' => $label.' wurde als PDF und XRechnung per E-Mail versendet.']);

        return back();
    }

    public function markPaid(Request $request, FinanceInvoice $invoice): RedirectResponse
    {
        if ($invoice->document_type !== 'invoice' || $invoice->status !== 'open') {
            return back();
        }
        $invoice->update([
            'status' => 'paid',
            'paid_at' => now(),
            'paid_by' => $request->user()->id,
            'paid_by_name' => $request->user()->name,
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rechnung '.$invoice->invoice_number.' wurde als bezahlt markiert.']);

        return back();
    }

    public function cancel(Request $request, FinanceInvoice $invoice, CancelFinanceInvoice $cancel): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'item_indices' => ['required', 'array', 'min:1', 'max:100'],
            'item_indices.*' => ['required', 'integer', 'min:0', 'distinct'],
        ]);
        $cancellation = $cancel->handle($invoice, $data['reason'], $data['item_indices'], $request->user());
        $label = ($cancellation->snapshot['cancellation_scope'] ?? 'full') === 'partial' ? 'Teilstornorechnung' : 'Stornorechnung';
        $message = $label.' '.$cancellation->invoice_number.' wurde erstellt und archiviert.';
        if (($cancellation->snapshot['original_status'] ?? null) === 'paid') {
            $message .= ' Die ursprüngliche Rechnung war bezahlt; eine notwendige Erstattung muss separat veranlasst werden.';
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    /** @param array<string, mixed> $filters
     * @return Builder<FinanceInvoice>
     */
    private function filteredQuery(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return FinanceInvoice::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $query->where(function (Builder $query) use ($term): void {
                    $query->where('invoice_number', 'like', $term)
                        ->orWhere('recipient_name', 'like', $term)
                        ->orWhere('recipient_email', 'like', $term)
                        ->orWhere('buyer_reference', 'like', $term);
                });
            })
            ->when(! empty($filters['from']), fn (Builder $query) => $query->whereDate('issue_date', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn (Builder $query) => $query->whereDate('issue_date', '<=', $filters['to']))
            ->when(! empty($filters['status']) && $filters['status'] !== 'all', fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(! empty($filters['document_type']) && $filters['document_type'] !== 'all', fn (Builder $query) => $query->where('document_type', $filters['document_type']));
    }
}
