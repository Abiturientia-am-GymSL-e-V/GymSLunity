<?php

declare(strict_types=1);

namespace App\Payments;

use App\Banking\BankCsvReader;
use App\Models\Contribution;
use App\Models\Member;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BankCsvImport
{
    /** @var Collection<int, Contribution>|null */
    private ?Collection $contributions = null;

    /** @var Collection<int, Member>|null */
    private ?Collection $mandateMembers = null;

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
        $csv = BankCsvReader::fromString($contents);
        $rows = $csv->rows;
        $amountIndex = $csv->column(['betrag', 'amount', 'umsatz']);
        $dateIndex = $csv->column(['buchungsdatum', 'datum', 'bookingdate']);
        if ($amountIndex === null || $dateIndex === null) {
            throw ValidationException::withMessages(['csv' => 'Benötigte Spalten: Buchungsdatum/Datum und Betrag. Optional: Mitgliedsnummer, Verwendungszweck, Referenz.']);
        }

        return DB::transaction(function () use ($rows, $csv, $amountIndex, $dateIndex, $actor, $file, $checksum): array {
            $importId = DB::table('payment_imports')->insertGetId([
                'actor_id' => $actor->id, 'actor_name' => $actor->name,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'checksum' => $checksum,
                'row_count' => count($rows), 'imported_count' => 0, 'unmatched_count' => 0,
                'result' => json_encode(['unmatched' => []], JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
            $imported = 0;
            $unmatchedRows = [];
            foreach ($rows as $number => $row) {
                $amount = BankCsvReader::amount($row[$amountIndex]);
                $date = BankCsvReader::date($row[$dateIndex]);
                $memberNumber = $csv->value($row, ['mitgliedsnummer', 'membernumber']);
                $reference = $csv->value($row, ['referenz', 'reference', 'endtoendid', 'endtoendreferenz']);
                $purpose = $csv->value($row, ['verwendungszweck', 'buchungstext', 'zweck', 'description']);
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

    private function member(string $explicitNumber, string $text): ?Member
    {
        if ($explicitNumber !== '' && ctype_digit($explicitNumber)) {
            return Member::query()->where('member_number', (int) $explicitNumber)->first();
        }
        // A bare number such as the year in "Beitrag 2026" is no member number.
        if (preg_match('/(?<![\p{L}\p{N}])(?:Mitgliedsnummer|Mitglieds-?Nr\.?|Mitglied|MNr\.?)\s*[:#]?\s*([0-9]{1,10})(?![\p{L}\p{N}])/iu', $text, $match)) {
            $member = Member::query()->where('member_number', (int) $match[1])->first();
            if ($member) {
                return $member;
            }
        }

        return BankCsvReader::uniqueMatch($text, $this->membersWithMandate(), fn (Member $member): array => [$member->mandate_reference]);
    }

    private function contribution(string $text): ?Contribution
    {
        return BankCsvReader::uniqueMatch($text, $this->referencedContributions(), fn (Contribution $item): array => [$item->payment_reference, $item->invoice_number]);
    }

    /** @return Collection<int, Contribution> */
    private function referencedContributions(): Collection
    {
        return $this->contributions ??= Contribution::query()->with('account.member')
            ->where(fn ($query) => $query->whereNotNull('payment_reference')->orWhereNotNull('invoice_number'))
            ->get();
    }

    /** @return Collection<int, Member> */
    private function membersWithMandate(): Collection
    {
        return $this->mandateMembers ??= Member::query()->whereNotNull('mandate_reference')->where('mandate_reference', '<>', '')->get();
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
