<?php

declare(strict_types=1);

namespace App\Finance;

use App\Banking\BankCsvReader;
use App\Models\FinanceInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FinanceBankCsvImport
{
    /** @var Collection<int, FinanceInvoice>|null */
    private ?Collection $invoices = null;

    /** @return array{rows: int, imported: int, unmatched: int} */
    public function handle(UploadedFile $file, User $actor): array
    {
        abort_unless($actor->fresh()?->can('view-finance'), 403);
        $contents = $file->get();
        if (! is_string($contents)) {
            throw ValidationException::withMessages(['csv' => 'Die CSV-Datei konnte nicht gelesen werden.']);
        }
        $checksum = hash('sha256', $contents);
        if (DB::table('finance_bank_imports')->where('checksum', $checksum)->exists()) {
            throw ValidationException::withMessages(['csv' => 'Diese Datei wurde im Rechnungswesen bereits importiert.']);
        }

        $csv = BankCsvReader::fromString($contents);
        $rows = $csv->rows;
        $amountIndex = $csv->column(['betrag', 'amount', 'umsatz']);
        $dateIndex = $csv->column(['buchungsdatum', 'datum', 'bookingdate']);
        if ($amountIndex === null || $dateIndex === null) {
            throw ValidationException::withMessages(['csv' => 'Benötigte Spalten: Buchungsdatum/Datum und Betrag. Optional: Verwendungszweck und Referenz.']);
        }

        return DB::transaction(function () use ($file, $actor, $checksum, $rows, $csv, $amountIndex, $dateIndex): array {
            $importId = DB::table('finance_bank_imports')->insertGetId([
                'actor_id' => $actor->id,
                'actor_name' => $actor->name,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'checksum' => $checksum,
                'row_count' => count($rows),
                'imported_count' => 0,
                'unmatched_count' => 0,
                'created_at' => now(),
            ]);
            $imported = 0;
            $unmatched = 0;
            foreach ($rows as $number => $row) {
                $amount = BankCsvReader::amount($row[$amountIndex]);
                $date = BankCsvReader::date($row[$dateIndex]);
                $reference = $csv->value($row, ['referenz', 'reference', 'endtoendid', 'endtoendreferenz']);
                $purpose = $csv->value($row, ['verwendungszweck', 'buchungstext', 'zweck', 'description']);
                $type = $amount < 0 ? 'return_debit' : 'payment';
                $invoice = $this->invoice($reference.' '.$purpose);
                $status = 'unmatched';
                $reason = null;
                if ($amount === 0 || $date === null) {
                    $reason = 'Betrag oder Datum ungültig';
                } elseif ($type === 'return_debit') {
                    $reason = $invoice
                        ? 'Rücklastschrift im gleichnamigen Reiter verarbeiten'
                        : 'Keine Rechnung automatisch erkannt';
                } elseif (! $invoice) {
                    $reason = 'Keine Rechnung automatisch erkannt';
                } else {
                    // Lock and re-read: an earlier row of this file may have paid it.
                    $invoice = FinanceInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                    $reason = $this->paymentProblem($invoice, $amount);
                    if ($reason === null) {
                        $this->markPaid($invoice, $date, $actor);
                        $status = 'matched';
                    }
                }
                DB::table('finance_bank_import_rows')->insert([
                    'finance_bank_import_id' => $importId,
                    'row_number' => $number + 2,
                    'booking_date' => $date,
                    'amount_cents' => $amount,
                    'purpose' => $purpose ?: null,
                    'reference' => $reference ?: null,
                    'type' => $type,
                    'status' => $status,
                    'reason' => $reason,
                    'finance_invoice_id' => $invoice?->id,
                    'raw' => json_encode($row, JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $status === 'matched' ? $imported++ : $unmatched++;
            }
            DB::table('finance_bank_imports')->where('id', $importId)->update([
                'imported_count' => $imported,
                'unmatched_count' => $unmatched,
            ]);

            return ['rows' => count($rows), 'imported' => $imported, 'unmatched' => $unmatched];
        }, attempts: 3);
    }

    public function assign(int $importId, int $rowId, FinanceInvoice $invoice, User $actor): void
    {
        DB::transaction(function () use ($importId, $rowId, $invoice, $actor): void {
            abort_unless($actor->fresh()?->can('view-finance'), 403);
            $row = DB::table('finance_bank_import_rows')
                ->where('finance_bank_import_id', $importId)
                ->where('id', $rowId)
                ->lockForUpdate()
                ->first();
            $lockedInvoice = FinanceInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if (! $row || $row->status !== 'unmatched' || $row->type !== 'payment' || ! $row->booking_date || (int) $row->amount_cents <= 0) {
                throw ValidationException::withMessages(['row' => 'Die Importzeile ist nicht als Zahlung zuordenbar.']);
            }
            $problem = $this->paymentProblem($lockedInvoice, (int) $row->amount_cents);
            if ($problem !== null) {
                throw ValidationException::withMessages(['invoice_id' => $problem]);
            }
            $this->markPaid($lockedInvoice, $row->booking_date, $actor);
            DB::table('finance_bank_import_rows')->where('id', $rowId)->update([
                'status' => 'matched',
                'finance_invoice_id' => $lockedInvoice->id,
                'reason' => null,
                'updated_at' => now(),
            ]);
            DB::table('finance_bank_imports')->where('id', $importId)->update([
                'imported_count' => DB::raw('imported_count + 1'),
                'unmatched_count' => DB::raw('unmatched_count - 1'),
            ]);
        }, attempts: 3);
    }

    public function ignore(int $importId, int $rowId): void
    {
        DB::transaction(function () use ($importId, $rowId): void {
            $updated = DB::table('finance_bank_import_rows')
                ->where('finance_bank_import_id', $importId)
                ->where('id', $rowId)
                ->where('status', 'unmatched')
                ->where('type', 'payment')
                ->update(['status' => 'ignored', 'updated_at' => now()]);
            if ($updated !== 1) {
                throw ValidationException::withMessages(['row' => 'Die Importzeile ist nicht mehr offen.']);
            }
            DB::table('finance_bank_imports')->where('id', $importId)->update([
                'unmatched_count' => DB::raw('unmatched_count - 1'),
            ]);
        }, attempts: 3);
    }

    private function paymentProblem(FinanceInvoice $invoice, int $amount): ?string
    {
        if ($invoice->document_type !== 'invoice' || $invoice->status !== 'open') {
            return 'Die Rechnung ist nicht offen.';
        }
        $remaining = $this->remaining($invoice);

        return $remaining === $amount
            ? null
            : 'Der Betrag stimmt nicht mit der offenen Forderung von '.number_format($remaining / 100, 2, ',', '.').' € überein.';
    }

    private function remaining(FinanceInvoice $invoice): int
    {
        $cancelled = (int) FinanceInvoice::query()->where('original_invoice_id', $invoice->id)->sum('total_cents');

        return max(0, $invoice->total_cents - $cancelled);
    }

    private function markPaid(FinanceInvoice $invoice, string $bookingDate, User $actor): void
    {
        $invoice->update([
            'status' => 'paid',
            'paid_at' => CarbonImmutable::parse($bookingDate)->startOfDay(),
            'paid_by' => $actor->id,
            'paid_by_name' => $actor->name,
        ]);
    }

    private function invoice(string $text): ?FinanceInvoice
    {
        $invoices = $this->invoices ??= FinanceInvoice::query()
            ->where('document_type', 'invoice')
            ->orderByDesc('id')
            ->get(['id', 'invoice_number', 'document_type', 'status', 'total_cents', 'snapshot']);
        $byNumber = BankCsvReader::uniqueMatch($text, $invoices, fn (FinanceInvoice $invoice): array => [$invoice->invoice_number]);
        if ($byNumber) {
            return $byNumber;
        }

        // A recurring mandate reference is shared by many invoices; it only
        // identifies the invoice when exactly one of them is still open.
        return BankCsvReader::uniqueMatch(
            $text,
            $invoices->where('status', 'open'),
            fn (FinanceInvoice $invoice): array => [is_string($invoice->snapshot['payment']['mandate_reference'] ?? null) ? $invoice->snapshot['payment']['mandate_reference'] : null],
        );
    }
}
