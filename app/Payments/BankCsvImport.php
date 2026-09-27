<?php

namespace App\Payments;

use App\Models\Contribution;
use App\Models\Member;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BankCsvImport
{
    public function __construct(private readonly ContributionLedger $ledger) {}

    /** @return array{rows: int, imported: int, unmatched: int} */
    public function handle(UploadedFile $file, User $actor): array
    {
        $contents = $file->get();
        if (! is_string($contents)) {
            throw ValidationException::withMessages(['csv' => 'Die CSV-Datei konnte nicht gelesen werden.']);
        }
        $checksum = hash('sha256', $contents);
        if (DB::table('payment_imports')->where('checksum', $checksum)->exists()) {
            throw ValidationException::withMessages(['csv' => 'Diese Datei wurde bereits importiert.']);
        }
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
        $normalized = array_map(fn (?string $header): string => $this->header($header ?? ''), $headers);
        $amountIndex = $this->column($normalized, ['betrag', 'amount', 'umsatz']);
        $dateIndex = $this->column($normalized, ['buchungsdatum', 'datum', 'bookingdate']);
        if ($amountIndex === null || $dateIndex === null) {
            fclose($stream);
            throw ValidationException::withMessages(['csv' => 'Benötigte Spalten: Buchungsdatum/Datum und Betrag. Optional: Mitgliedsnummer, Verwendungszweck, Referenz.']);
        }

        $rows = [];
        while (($row = fgetcsv($stream, separator: $delimiter, escape: '')) !== false) {
            if (count(array_filter($row, fn ($value): bool => trim((string) $value) !== '')) === 0) {
                continue;
            }
            $rows[] = array_pad($row, count($headers), '');
        }
        fclose($stream);
        if (count($rows) > 5000) {
            throw ValidationException::withMessages(['csv' => 'Pro Import sind höchstens 5.000 Buchungen erlaubt.']);
        }

        return DB::transaction(function () use ($rows, $normalized, $amountIndex, $dateIndex, $actor, $file, $checksum): array {
            $importId = DB::table('payment_imports')->insertGetId([
                'actor_id' => $actor->id, 'actor_name' => $actor->name,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'checksum' => $checksum,
                'row_count' => count($rows), 'imported_count' => 0, 'unmatched_count' => 0,
                'result' => json_encode(['unmatched' => []], JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
            $imported = 0;
            $unmatchedRows = [];
            foreach ($rows as $number => $row) {
                $amount = $this->amount((string) $row[$amountIndex]);
                $date = $this->date((string) $row[$dateIndex]);
                $memberNumber = $this->value($row, $normalized, ['mitgliedsnummer', 'membernumber']);
                $reference = $this->value($row, $normalized, ['referenz', 'reference', 'endtoendid', 'endtoendreferenz']);
                $purpose = $this->value($row, $normalized, ['verwendungszweck', 'buchungstext', 'zweck', 'description']);
                $type = $amount < 0 ? 'return_debit' : 'payment';
                if ($amount === 0 || ! $date) {
                    $reason = 'Betrag oder Datum ungültig';
                    $this->storeRow($importId, $number + 2, $date, $amount, $purpose, $reference, $type, $reason, $row);
                    $unmatchedRows[] = ['row' => $number + 2, 'reason' => $reason];

                    continue;
                }
                $text = $reference.' '.$purpose;
                $contribution = $this->contribution($text);
                $member = $contribution?->account->member ?? $this->member($memberNumber, $text);
                if (! $member) {
                    $reason = 'Kein Mitglied zugeordnet';
                    $this->storeRow($importId, $number + 2, $date, $amount, $purpose, $reference, $type, $reason, $row);
                    $unmatchedRows[] = ['row' => $number + 2, 'reason' => $reason];

                    continue;
                }
                $metadata = ['import_id' => $importId, 'import_checksum' => $checksum, 'row' => $number + 2];
                if ($amount < 0) {
                    $this->ledger->returnDebit(
                        $member, $actor, abs($amount), $date,
                        $purpose ?: 'Rücklastschrift aus Bankimport', $reference ?: null, $contribution, $metadata,
                    );
                } else {
                    $this->ledger->payment(
                        $member, $actor, $amount, $date,
                        $purpose ?: 'Bankimport', $reference ?: null, $contribution, 'bank_payment', $metadata,
                    );
                }
                $imported++;
            }
            DB::table('payment_imports')->where('id', $importId)->update([
                'imported_count' => $imported,
                'unmatched_count' => count($unmatchedRows),
                'result' => json_encode(['unmatched' => array_slice($unmatchedRows, 0, 100)], JSON_THROW_ON_ERROR),
            ]);

            return ['rows' => count($rows), 'imported' => $imported, 'unmatched' => count($unmatchedRows)];
        }, attempts: 3);
    }

    public function assign(int $importId, int $rowId, Member $member, User $actor): void
    {
        DB::transaction(function () use ($importId, $rowId, $member, $actor): void {
            $row = DB::table('payment_import_rows')->where('payment_import_id', $importId)->where('id', $rowId)->lockForUpdate()->first();
            if (! $row || $row->status !== 'unmatched' || ! $row->booking_date || (int) $row->amount_cents === 0) {
                throw ValidationException::withMessages(['row' => 'Die Importzeile ist nicht mehr zuordenbar.']);
            }
            $metadata = ['import_id' => $importId, 'row' => (int) $row->row_number, 'manually_assigned' => true];
            $contribution = $this->contribution(trim((string) $row->reference.' '.(string) $row->purpose));
            if ($contribution?->account->member_id !== $member->id) {
                $contribution = null;
            }
            $transaction = (int) $row->amount_cents < 0
                ? $this->ledger->returnDebit($member, $actor, abs((int) $row->amount_cents), $row->booking_date, $row->purpose ?: 'Rücklastschrift aus Bankimport', $row->reference, $contribution, $metadata)
                : $this->ledger->payment($member, $actor, (int) $row->amount_cents, $row->booking_date, $row->purpose ?: 'Bankimport', $row->reference, $contribution, 'bank_payment', $metadata);
            DB::table('payment_import_rows')->where('id', $rowId)->update([
                'status' => 'matched', 'member_id' => $member->id, 'transaction_id' => $transaction->id, 'reason' => null, 'updated_at' => now(),
            ]);
            DB::table('payment_imports')->where('id', $importId)->update([
                'imported_count' => DB::raw('imported_count + 1'),
                'unmatched_count' => DB::raw('unmatched_count - 1'),
            ]);
        }, attempts: 3);
    }

    public function ignore(int $importId, int $rowId): void
    {
        DB::transaction(function () use ($importId, $rowId): void {
            $updated = DB::table('payment_import_rows')->where('payment_import_id', $importId)->where('id', $rowId)
                ->where('status', 'unmatched')->update(['status' => 'ignored', 'updated_at' => now()]);
            if ($updated !== 1) {
                throw ValidationException::withMessages(['row' => 'Die Importzeile ist nicht mehr offen.']);
            }
            DB::table('payment_imports')->where('id', $importId)->update(['unmatched_count' => DB::raw('unmatched_count - 1')]);
        }, attempts: 3);
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

    /** @param list<string> $row
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

    private function member(string $explicitNumber, string $text): ?Member
    {
        if ($explicitNumber !== '' && ctype_digit($explicitNumber)) {
            return Member::query()->where('member_number', (int) $explicitNumber)->first();
        }
        if (preg_match('/(?:Mitglied|Mitgliedsnummer|MNr\.?|Nr\.?)?\s*#?([0-9]{4,10})/iu', $text, $match)) {
            $member = Member::query()->where('member_number', (int) $match[1])->first();
            if ($member) {
                return $member;
            }
        }
        $mandate = Member::query()->whereNotNull('mandate_reference')->get()
            ->first(fn (Member $member): bool => str_contains($text, (string) $member->mandate_reference));

        return $mandate;
    }

    private function contribution(string $text): ?Contribution
    {
        $normalized = mb_strtoupper($text);

        return Contribution::query()->with('account.member')
            ->where(fn ($query) => $query->whereNotNull('payment_reference')->orWhereNotNull('invoice_number'))
            ->get()->first(fn (Contribution $item): bool => ($item->payment_reference && str_contains($normalized, mb_strtoupper($item->payment_reference)))
                || ($item->invoice_number && str_contains($normalized, mb_strtoupper($item->invoice_number))));
    }

    /** @param list<string|null> $raw */
    private function storeRow(int $importId, int $rowNumber, ?string $date, int $amount, string $purpose, string $reference, string $type, string $reason, array $raw): void
    {
        DB::table('payment_import_rows')->insert([
            'payment_import_id' => $importId,
            'row_number' => $rowNumber,
            'booking_date' => $date,
            'amount_cents' => $amount,
            'purpose' => $purpose ?: null,
            'reference' => $reference ?: null,
            'type' => $type,
            'status' => 'unmatched',
            'reason' => $reason,
            'raw' => json_encode($raw, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
