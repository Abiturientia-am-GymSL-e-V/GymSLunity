<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Finance\FinanceBankCsvImport;
use App\Http\Controllers\Controller;
use App\Models\FinanceInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FinanceBankImportController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('finance/BankImport', [
            'recentImports' => DB::table('finance_bank_imports')
                ->latest('created_at')
                ->limit(10)
                ->get(['id', 'original_name', 'row_count', 'imported_count', 'unmatched_count', 'created_at']),
            'unmatchedRows' => DB::table('finance_bank_import_rows')
                ->join('finance_bank_imports', 'finance_bank_imports.id', '=', 'finance_bank_import_rows.finance_bank_import_id')
                ->where('finance_bank_import_rows.status', 'unmatched')
                ->where('finance_bank_import_rows.type', 'payment')
                ->latest('finance_bank_import_rows.created_at')
                ->limit(200)
                ->get([
                    'finance_bank_import_rows.id', 'finance_bank_import_rows.finance_bank_import_id',
                    'finance_bank_import_rows.row_number', 'finance_bank_import_rows.booking_date',
                    'finance_bank_import_rows.amount_cents', 'finance_bank_import_rows.purpose',
                    'finance_bank_import_rows.reference', 'finance_bank_import_rows.reason',
                    'finance_bank_imports.original_name',
                ]),
            'openInvoices' => $this->openInvoices(),
        ]);
    }

    public function import(Request $request, FinanceBankCsvImport $import): RedirectResponse
    {
        $request->validate(['csv' => ['required', 'file', 'max:5120', 'mimes:csv,txt']]);
        $result = $import->handle($request->file('csv'), $request->user());
        Inertia::flash('toast', [
            'type' => $result['unmatched'] ? 'warning' : 'success',
            'message' => $result['imported'].' Zahlung(en) verbucht'.($result['unmatched'] ? ', '.$result['unmatched'].' Zeile(n) noch offen.' : '.'),
        ]);

        return back();
    }

    public function assign(Request $request, int $import, int $row, FinanceBankCsvImport $bankImport): RedirectResponse
    {
        $data = $request->validate(['invoice_id' => ['required', 'integer', 'exists:finance_invoices,id']]);
        $invoice = FinanceInvoice::query()->whereKey($data['invoice_id'])->firstOrFail();
        $bankImport->assign($import, $row, $invoice, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Die Bankbuchung wurde der Rechnung zugeordnet und als Zahlung verbucht.']);

        return back();
    }

    public function ignore(int $import, int $row, FinanceBankCsvImport $bankImport): RedirectResponse
    {
        $bankImport->ignore($import, $row);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Die Bankbuchung wurde als nicht rechnungsrelevant markiert.']);

        return back();
    }

    /** @return list<array<string, mixed>> */
    private function openInvoices(): array
    {
        return array_values(FinanceInvoice::query()
            ->where('document_type', 'invoice')
            ->where('status', 'open')
            ->withSum('cancellations', 'total_cents')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get(['id', 'invoice_number', 'recipient_name', 'total_cents', 'currency'])
            ->map(fn (FinanceInvoice $invoice): array => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'recipient_name' => $invoice->recipient_name,
                'currency' => $invoice->currency,
                'amount_cents' => max(0, $invoice->total_cents - (int) $invoice->cancellations_sum_total_cents),
            ])
            ->filter(fn (array $invoice): bool => $invoice['amount_cents'] > 0)
            ->values()
            ->all());
    }
}
