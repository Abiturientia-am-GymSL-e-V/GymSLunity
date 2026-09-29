<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Bookings\BookingManager;
use App\Configuration\ClubSettings;
use App\Configuration\MailConfigurator;
use App\Finance\CancelFinanceInvoice;
use App\Finance\IssueFinanceInvoice;
use App\Finance\RecordFinanceRefund;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\FinanceInvoiceFilterRequest;
use App\Http\Requests\Finance\StoreFinanceInvoiceRequest;
use App\Mail\FinanceInvoiceMail;
use App\Members\MemberReportWriter;
use App\Models\FinanceInvoice;
use App\Models\FinanceMandate;
use App\Models\ResourceBooking;
use App\Support\Clock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class FinanceInvoiceController extends Controller
{
    public function __construct(private readonly ClubSettings $clubSettings) {}

    public function index(FinanceInvoiceFilterRequest $request, CancelFinanceInvoice $cancel): Response
    {
        $normalizedFilters = $request->filters();
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
                'refund_due' => $invoice->refundDue(),
                'refunded_at' => $invoice->refunded_at?->toDateString(),
                'refunded_by_name' => $invoice->refunded_by_name,
                'refund_reference' => $invoice->refund_reference,
                'cancellable_items' => $cancellableItems,
                'partially_cancelled' => $cancellableItems !== [] && count($cancellableItems) < $itemCount,
            ];
        });
        // Cancellations of paid invoices: owed back until the refund is recorded.
        $paidCancellations = FinanceInvoice::query()
            ->where('document_type', 'cancellation')
            ->get(['id', 'document_type', 'total_cents', 'snapshot', 'refunded_at'])
            ->filter(fn (FinanceInvoice $cancellation): bool => ($cancellation->snapshot['original_status'] ?? null) === 'paid');
        $refundPending = (int) $paidCancellations->filter(fn (FinanceInvoice $cancellation): bool => $cancellation->refunded_at === null)->sum('total_cents');
        $refunded = (int) $paidCancellations->filter(fn (FinanceInvoice $cancellation): bool => $cancellation->refunded_at !== null)->sum('total_cents');
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
                'open_cents' => (int) $openInvoices->sum(fn (FinanceInvoice $invoice): int => max(0, $invoice->total_cents - (int) $invoice->cancellations_sum_total_cents)) - $refundPending,
                'refund_pending_cents' => $refundPending,
                'paid_cents' => (int) FinanceInvoice::query()->where('document_type', 'invoice')->whereNotNull('paid_at')->sum('total_cents') - $refunded,
            ],
        ]);
    }

    public function report(FinanceInvoiceFilterRequest $request): HttpResponse
    {
        $filters = $request->filters();
        $query = $this->filteredQuery($filters)
            ->select(['id', 'invoice_number', 'document_type', 'recipient_name', 'recipient_email', 'issue_date', 'due_date', 'payment_method', 'currency', 'total_cents', 'status'])
            ->latest('issue_date')->latest('id');
        if ((clone $query)->count() > 5000) {
            throw ValidationException::withMessages(['scope' => 'Der Bericht ist auf 5.000 Belege begrenzt. Bitte den Zeitraum oder die Filter einschränken.']);
        }
        $invoices = $query->get();
        $settings = $this->clubSettings;
        $club = $settings->data();
        $logo = $settings->logoDataUri();
        $printedAt = now()->setTimezone(config('app.display_timezone'));
        $html = view('finance.invoice-report', compact('invoices', 'filters', 'club', 'logo', 'printedAt'))->render();
        $pdf = MemberReportWriter::pdf($html, true);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="rechnungsbuch-'.Clock::localNow()->format('Y-m-d-His').'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function create(Request $request, BookingManager $bookingManager): Response
    {
        $club = $this->clubSettings->data();
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

        $booking = $request->filled('booking') ? ResourceBooking::query()->with('resource:id,name')->find($request->integer('booking')) : null;
        $bookingPrefill = null;
        if ($booking !== null) {
            abort_unless($booking->member_id === null && $booking->status === 'confirmed' && $booking->price_cents > 0
                && $booking->finance_invoice_id === null && $bookingManager->isChargeDue($booking), 422);
            $bookingPrefill = [
                'id' => $booking->id,
                'recipient_name' => $booking->requester_name,
                'buyer_reference' => 'BUCHUNG-'.$booking->id,
                'service_date' => $booking->starts_at->setTimezone(config('app.display_timezone'))->toDateString(),
                'description' => 'Buchung '.$booking->resource->name.': '.$booking->title,
                'unit_price' => number_format($booking->price_cents / 100, 2, '.', ''),
            ];
        }

        return Inertia::render('finance/CreateInvoice', [
            'creationKey' => (string) Str::uuid(),
            'today' => Clock::todayString(),
            'defaultDueDate' => now()->addDays(14)->toDateString(),
            'defaultCountry' => $club['country'] ?? 'DE',
            'paymentReadiness' => [
                'bank_transfer' => ! empty($club['iban']) && ! empty($club['account_holder']),
                'sepa_direct_debit' => $this->clubSettings->sepaReady(),
            ],
            'smallBusinessRegulationEnabled' => (bool) ($club['small_business_regulation_enabled'] ?? false),
            'smallBusinessNotice' => IssueFinanceInvoice::SMALL_BUSINESS_NOTICE,
            'bookingPrefill' => $bookingPrefill,
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

    public function store(StoreFinanceInvoiceRequest $request, IssueFinanceInvoice $issue): RedirectResponse
    {
        $data = $request->validated();
        $mandate = $request->mandate();
        if ($mandate !== null) {
            $data['debtor_iban'] = $mandate->iban;
            $data['mandate_reference'] = $mandate->mandate_reference;
            $data['mandate_signed_at'] = $mandate->signed_at?->toDateString();
            $data['mandate_type'] = $mandate->mandate_type;
        }
        $data['notes'] ??= '';
        $invoice = $issue->handle($data, $request->user());
        if (isset($data['booking_id'])) {
            ResourceBooking::query()->whereKey((int) $data['booking_id'])->whereNull('finance_invoice_id')
                ->update(['finance_invoice_id' => $invoice->id]);
        }
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
        $marked = DB::transaction(function () use ($request, $invoice): bool {
            $locked = FinanceInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($locked->document_type !== 'invoice' || $locked->status !== 'open') {
                return false;
            }
            $locked->update([
                'status' => 'paid',
                'paid_at' => now(),
                'paid_by' => $request->user()->id,
                'paid_by_name' => $request->user()->name,
            ]);

            return true;
        });
        Inertia::flash('toast', $marked
            ? ['type' => 'success', 'message' => 'Rechnung '.$invoice->invoice_number.' wurde als bezahlt markiert.']
            : ['type' => 'error', 'message' => 'Rechnung '.$invoice->invoice_number.' ist nicht mehr offen und wurde nicht verändert.']);

        return back();
    }

    public function refund(Request $request, FinanceInvoice $invoice, RecordFinanceRefund $refund): RedirectResponse
    {
        $data = $request->validate([
            'refunded_at' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$invoice->issue_date->toDateString(), 'before_or_equal:'.Clock::todayString()],
            'refund_reference' => ['nullable', 'string', 'max:255'],
        ]);
        $refund->handle($invoice, $request->user(), $data['refunded_at'], $data['refund_reference'] ?? null);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Die Erstattung zu '.$invoice->invoice_number.' wurde erfasst.']);

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
            $message .= ' Die ursprüngliche Rechnung war bezahlt; die Rückzahlung bitte veranlassen und anschließend in der Liste als Erstattung erfassen.';
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
