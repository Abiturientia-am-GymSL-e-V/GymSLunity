<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Finance\FinanceSepaDirectDebit;
use App\Http\Controllers\Controller;
use App\Models\ClubSetting;
use App\Models\FinanceInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FinanceSepaExportController extends Controller
{
    public function index(): Response
    {
        $settings = ClubSetting::current()->data;
        $invoices = FinanceInvoice::query()
            ->where('document_type', 'invoice')
            ->where('payment_method', 'sepa_direct_debit')
            ->where('status', 'open')
            ->whereNull('sepa_exported_at')
            ->withSum('cancellations', 'total_cents')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get(['id', 'invoice_number', 'recipient_name', 'due_date', 'currency', 'total_cents', 'snapshot'])
            ->map(function (FinanceInvoice $invoice): array {
                $payment = is_array($invoice->snapshot['payment'] ?? null) ? $invoice->snapshot['payment'] : [];

                return [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'recipient_name' => $invoice->recipient_name,
                    'collection_from' => $invoice->due_date->toDateString(),
                    'currency' => $invoice->currency,
                    'amount_cents' => max(0, $invoice->total_cents - (int) $invoice->cancellations_sum_total_cents),
                    'mandate_reference' => (string) ($payment['mandate_reference'] ?? ''),
                ];
            })
            ->filter(fn (array $invoice): bool => $invoice['amount_cents'] > 0)
            ->values();

        return Inertia::render('finance/SepaExport', [
            'invoices' => $invoices,
            'exports' => DB::table('sepa_exports')
                ->join('finance_sepa_export_items', 'finance_sepa_export_items.sepa_export_uuid', '=', 'sepa_exports.uuid')
                ->select([
                    'sepa_exports.uuid', 'sepa_exports.message_id', 'sepa_exports.collection_date',
                    'sepa_exports.actor_name', 'sepa_exports.created_at', 'sepa_exports.reverted_at',
                    'sepa_exports.reverted_by_name', 'sepa_exports.reversal_reason',
                ])
                ->selectRaw('COUNT(finance_sepa_export_items.id) as transaction_count')
                ->selectRaw('SUM(finance_sepa_export_items.amount_cents) as total_cents')
                ->selectRaw("MAX(CASE WHEN finance_sepa_export_items.status = 'returned' THEN 1 ELSE 0 END) as has_returns")
                ->groupBy([
                    'sepa_exports.uuid', 'sepa_exports.message_id', 'sepa_exports.collection_date',
                    'sepa_exports.actor_name', 'sepa_exports.created_at', 'sepa_exports.reverted_at',
                    'sepa_exports.reverted_by_name', 'sepa_exports.reversal_reason',
                ])
                ->latest('sepa_exports.created_at')
                ->limit(20)
                ->get(),
            'today' => now()->toDateString(),
            'sepaReady' => ! empty($settings['name']) && ! empty($settings['iban']) && ! empty($settings['creditor_id']),
        ]);
    }

    public function export(Request $request, FinanceSepaDirectDebit $export): HttpResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:1000'],
            'ids.*' => ['integer', 'distinct', 'exists:finance_invoices,id'],
            'collection_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
        ]);
        $xml = $export->export($data['ids'], $data['collection_date'], $request->user());

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="sepa-rechnungen-'.now()->format('Y-m-d-His').'.xml"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function reverse(Request $request, string $uuid, FinanceSepaDirectDebit $export): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $count = $export->reverse($uuid, $data['reason'], $request->user());
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $count === 0
                ? 'Der SEPA-Export war bereits zurückgesetzt.'
                : $count.' Rechnung(en) wurden wieder als offen markiert.',
        ]);

        return back();
    }
}
