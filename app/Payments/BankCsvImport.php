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
            $imported = 0;
            $unmatchedRows = [];
            foreach ($rows as $number => $row) {
                $amount = $this->amount((string) $row[$amountIndex]);
                $date = $this->date((string) $row[$dateIndex]);
                if ($amount <= 0 || ! $date) {
                    $unmatchedRows[] = ['row' => $number + 2, 'reason' => 'Betrag oder Datum ungültig'];

                    continue;
                }
                $memberNumber = $this->value($row, $normalized, ['mitgliedsnummer', 'membernumber']);
                $reference = $this->value($row, $normalized, ['referenz', 'reference', 'endtoendid']);
                $purpose = $this->value($row, $normalized, ['verwendungszweck', 'buchungstext', 'zweck', 'description']);
                $member = $this->member($memberNumber, $reference.' '.$purpose);
                if (! $member) {
                    $unmatchedRows[] = ['row' => $number + 2, 'reason' => 'Kein Mitglied zugeordnet'];

                    continue;
                }
                $this->ledger->payment(
                    $member, $actor, $amount, $date,
                    $purpose ?: 'Bankimport', $reference ?: null, kind: 'bank_payment',
                    metadata: ['import_checksum' => $checksum, 'row' => $number + 2],
                );
                $imported++;
            }
            DB::table('payment_imports')->insert([
                'actor_id' => $actor->id, 'actor_name' => $actor->name,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'checksum' => $checksum,
                'row_count' => count($rows), 'imported_count' => $imported, 'unmatched_count' => count($unmatchedRows),
                'result' => json_encode(['unmatched' => array_slice($unmatchedRows, 0, 100)], JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);

            return ['rows' => count($rows), 'imported' => $imported, 'unmatched' => count($unmatchedRows)];
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
        $contribution = Contribution::query()->with('account.member')->whereNotNull('invoice_number')
            ->get()->first(fn (Contribution $item): bool => str_contains($text, (string) $item->invoice_number));
        if ($contribution) {
            return $contribution->account->member;
        }
        $mandate = Member::query()->whereNotNull('mandate_reference')->get()
            ->first(fn (Member $member): bool => str_contains($text, (string) $member->mandate_reference));

        return $mandate;
    }
}
