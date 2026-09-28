<?php

declare(strict_types=1);

namespace App\Finance;

use App\Models\FinanceInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FinanceBankCsvImport
{
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

        [$headers, $rows] = $this->rows($contents);
        $normalized = array_map(fn (?string $header): string => $this->header($header ?? ''), $headers);
        $amountIndex = $this->column($normalized, ['betrag', 'amount', 'umsatz']);
        $dateIndex = $this->column($normalized, ['buchungsdatum', 'datum', 'bookingdate']);
        if ($amountIndex === null || $dateIndex === null) {
            throw ValidationException::withMessages(['csv' => 'Benötigte Spalten: Buchungsdatum/Datum und Betrag. Optional: Verwendungszweck und Referenz.']);
        }

        return DB::transaction(function () use ($file, $actor, $checksum, $rows, $normalized, $amountIndex, $dateIndex): array {
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
                $amount = $this->amount((string) $row[$amountIndex]);
                $date = $this->date((string) $row[$dateIndex]);
                $reference = $this->value($row, $normalized, ['referenz', 'reference', 'endtoendid', 'endtoendreferenz']);
                $purpose = $this->value($row, $normalized, ['verwendungszweck', 'buchungstext', 'zweck', 'description']);
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

    /** @return array{list<string|null>, list<list<string|null>>} */
    private function rows(string $contents): array
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw ValidationException::withMessages(['csv' => 'Die CSV-Datei konnte nicht gelesen werden.']);
        }
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents);
        rewind($stream);
        $sample = fgets($stream) ?: '';
        $delimiter = substr_count($sample, ';') >= substr_count($sample, ',') ? ';' : ',';
        rewind($stream);
        $headers = fgetcsv($stream, separator: $delimiter, escape: '');
        if (! is_array($headers)) {
            fclose($stream);
            throw ValidationException::withMessages(['csv' => 'Die CSV-Datei hat keine Kopfzeile.']);
        }
        $rows = [];
        while (($row = fgetcsv($stream, separator: $delimiter, escape: '')) !== false) {
            if (count(array_filter($row, fn ($value): bool => trim((string) $value) !== '')) > 0) {
                $rows[] = array_pad($row, count($headers), '');
            }
        }
        fclose($stream);
        if (count($rows) > 5000) {
            throw ValidationException::withMessages(['csv' => 'Pro Import sind höchstens 5.000 Buchungen erlaubt.']);
        }

        return [$headers, $rows];
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
        $normalized = mb_strtoupper($text);

        $invoices = FinanceInvoice::query()
            ->where('document_type', 'invoice')
            ->orderByDesc('id')
            ->get(['id', 'invoice_number', 'document_type', 'status', 'total_cents', 'snapshot']);
        $byNumber = $invoices->first(
            fn (FinanceInvoice $invoice): bool => str_contains($normalized, mb_strtoupper($invoice->invoice_number)),
        );
        if ($byNumber) {
            return $byNumber;
        }

        return $invoices->first(function (FinanceInvoice $invoice) use ($normalized): bool {
            $reference = $invoice->snapshot['payment']['mandate_reference'] ?? null;

            return is_string($reference) && $reference !== '' && str_contains($normalized, mb_strtoupper($reference));
        });
    }

    private function header(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($value))) ?? '';
    }

    /** @param list<string> $headers
     * @param  list<string>  $names
     */
    private function column(array $headers, array $names): ?int
    {
        foreach ($names as $name) {
            $index = array_search($name, $headers, true);
            if ($index !== false) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<string|null> $row
     * @param  list<string>  $headers
     * @param  list<string>  $names
     */
    private function value(array $row, array $headers, array $names): string
    {
        $index = $this->column($headers, $names);

        return $index === null ? '' : trim((string) ($row[$index] ?? ''));
    }

    private function amount(string $value): int
    {
        $value = preg_replace('/[^0-9,.-]/', '', $value) ?? '';
        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace('.', '', $value);
        }
        $value = str_replace(',', '.', $value);

        return is_numeric($value) ? (int) round((float) $value * 100) : 0;
    }

    private function date(string $value): ?string
    {
        foreach (['!Y-m-d', '!d.m.Y', '!d/m/Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, trim($value));
            if ($date && $date->format(substr($format, 1)) === trim($value)) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }
}
