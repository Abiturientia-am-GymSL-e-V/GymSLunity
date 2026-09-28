<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Finance\FinanceReturnDebit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreFinanceReturnDebitRequest;
use App\Models\FinanceInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FinanceReturnDebitController extends Controller
{
    public function index(): Response
    {
        $rows = DB::table('finance_bank_import_rows')
            ->join('finance_bank_imports', 'finance_bank_imports.id', '=', 'finance_bank_import_rows.finance_bank_import_id')
            ->where('finance_bank_import_rows.status', 'unmatched')
            ->where('finance_bank_import_rows.type', 'return_debit')
            ->latest('finance_bank_import_rows.created_at')
            ->limit(200)
            ->get([
                'finance_bank_import_rows.id', 'finance_bank_import_rows.row_number',
                'finance_bank_import_rows.booking_date', 'finance_bank_import_rows.amount_cents',
                'finance_bank_import_rows.purpose', 'finance_bank_import_rows.reference',
                'finance_bank_import_rows.reason', 'finance_bank_import_rows.finance_invoice_id',
                'finance_bank_imports.original_name',
            ]);

        return Inertia::render('finance/ReturnDebits', [
            'rows' => $rows,
            'invoices' => FinanceInvoice::query()
                ->where('document_type', 'invoice')
                ->where('payment_method', 'sepa_direct_debit')
                ->where('status', 'paid')
                ->whereNotNull('sepa_exported_at')
                ->withSum('cancellations', 'total_cents')
                ->orderByDesc('paid_at')
                ->get(['id', 'invoice_number', 'recipient_name', 'total_cents', 'currency', 'mandate_type'])
                ->map(fn (FinanceInvoice $invoice): array => [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'recipient_name' => $invoice->recipient_name,
                    'amount_cents' => max(0, $invoice->total_cents - (int) $invoice->cancellations_sum_total_cents),
                    'currency' => $invoice->currency,
                    'mandate_type' => $invoice->mandate_type ?? 'recurring',
                ]),
            'defaultDueDate' => now()->addDays(14)->toDateString(),
        ]);
    }

    public function store(StoreFinanceReturnDebitRequest $request, int $row, FinanceReturnDebit $returnDebit): RedirectResponse
    {
        $data = $request->validated();
        $feeInvoice = $returnDebit->handle($row, $data, $request->user());
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Die ursprüngliche Rechnung wurde wieder geöffnet und die Kostenrechnung '.$feeInvoice->invoice_number.' erstellt.',
        ]);

        return back();
    }
}
