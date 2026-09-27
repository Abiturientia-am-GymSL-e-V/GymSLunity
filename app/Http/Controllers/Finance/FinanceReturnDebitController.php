<?php

namespace App\Http\Controllers\Finance;

use App\Finance\FinanceReturnDebit;
use App\Http\Controllers\Controller;
use App\Models\FinanceInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

    public function store(Request $request, int $row, FinanceReturnDebit $returnDebit): RedirectResponse
    {
        $request->merge(['fee_amount' => str_replace(',', '.', (string) $request->input('fee_amount'))]);
        $data = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:finance_invoices,id'],
            'fee_amount' => ['required', 'decimal:0,2', 'min:0.01', 'max:9999999.99'],
            'description' => ['required', 'string', 'max:500'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'vat_rate' => ['required', Rule::in([0, 7, 19])],
            'tax_exemption_reason' => ['nullable', 'string', 'max:500'],
        ]);
        if ((int) $data['vat_rate'] === 0 && trim((string) ($data['tax_exemption_reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['tax_exemption_reason' => 'Für 0 % Umsatzsteuer ist ein Befreiungs- oder Nichtsteuerbarkeitsgrund erforderlich.']);
        }
        $feeInvoice = $returnDebit->handle($row, $data, $request->user());
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Die ursprüngliche Rechnung wurde wieder geöffnet und die Kostenrechnung '.$feeInvoice->invoice_number.' erstellt.',
        ]);

        return back();
    }
}
