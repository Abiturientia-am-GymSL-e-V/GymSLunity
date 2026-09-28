<?php

declare(strict_types=1);

namespace App\Payments;

use App\Configuration\ClubSettings;
use App\Models\Contribution;
use App\Models\ContributionAccount;
use App\Models\User;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SepaDirectDebit
{
    public const NS = 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.08';

    public function __construct(private readonly ClubSettings $clubSettings,
        private readonly ContributionLedger $ledger,
        private readonly SepaXmlValidator $validator,
    ) {}

    /** @param list<int> $ids */
    public function export(array $ids, string $collectionDate, User $actor): string
    {
        $club = $this->clubSettings->data();
        foreach ($this->clubSettings->missingSepaFields() as $label) {
            throw ValidationException::withMessages(['ids' => $label.' fehlt in der Vereinskonfiguration.']);
        }

        return DB::transaction(function () use ($ids, $collectionDate, $actor, $club): string {
            $accountIds = Contribution::query()->whereKey($ids)->pluck('account_id')->unique()->sort()->values();
            $accounts = ContributionAccount::query()->whereKey($accountIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($accounts->count() !== $accountIds->count()) {
                throw ValidationException::withMessages(['ids' => 'Mindestens ein Beitragskonto wurde nicht gefunden.']);
            }
            $contributions = Contribution::query()->with('account.member')->whereKey($ids)
                ->orderBy('account_id')->orderBy('due_date')->orderBy('id')->lockForUpdate()->get();
            if ($contributions->count() !== count($ids)) {
                throw ValidationException::withMessages(['ids' => 'Mindestens ein Beitrag wurde nicht gefunden.']);
            }
            foreach ($contributions as $contribution) {
                $member = $contribution->account->member;
                if ($contribution->status !== 'open' || $contribution->remainingCents() <= 0 || $contribution->payment_method !== 'SEPA-Lastschrift'
                    || ! $member->iban || ! $member->mandate_reference || ! $member->mandate_signed_at) {
                    throw ValidationException::withMessages(['ids' => 'Die Auswahl enthält nicht einziehbare oder unvollständige Beiträge.']);
                }
            }

            $sequences = $this->sequences($contributions);
            $uuid = (string) Str::uuid();
            $messageId = 'GYMSL-'.now()->format('YmdHis').'-'.substr(str_replace('-', '', $uuid), 0, 8);
            $xml = $this->xml($contributions, $sequences, $club, $collectionDate, $messageId);
            $this->validator->validate($xml);

            DB::table('sepa_exports')->insert([
                'uuid' => $uuid, 'actor_id' => $actor->id, 'actor_name' => $actor->name,
                'message_id' => $messageId, 'transaction_count' => $contributions->count(),
                'total_cents' => $contributions->sum(fn (Contribution $item): int => $item->remainingCents()),
                'collection_date' => $collectionDate, 'content_hash' => hash('sha256', $xml), 'created_at' => now(),
            ]);
            foreach ($contributions as $contribution) {
                $sequence = $sequences[$contribution->id];
                $member = $contribution->account->member;
                $contribution->update([
                    'mandate_reference' => $member->mandate_reference,
                    'mandate_sequence' => $sequence,
                    'sepa_exported_at' => now(),
                ]);
                $this->ledger->payment(
                    $member,
                    $actor,
                    $contribution->remainingCents(),
                    $collectionDate,
                    'SEPA-Lastschrift '.$messageId,
                    $contribution->payment_reference ?? $contribution->invoice_number ?? 'BEITRAG-'.$contribution->id,
                    $contribution,
                    'sepa_payment',
                    [
                        'sepa_export_uuid' => $uuid,
                        'message_id' => $messageId,
                        'mandate_reference' => $member->mandate_reference,
                        'sequence_type' => $sequence,
                    ],
                    lockedAccount: $accounts->get($contribution->account_id),
                );
            }

            return $xml;
        }, attempts: 3);
    }

    /** @param Collection<int, Contribution> $contributions
     * @return array<int, 'FNAL'|'FRST'|'OOFF'|'RCUR'>
     */
    private function sequences(Collection $contributions): array
    {
        $result = [];
        $seen = [];
        $accountIds = $contributions->pluck('account_id')->unique()->values();
        $previouslyUsed = Contribution::query()
            ->whereIn('account_id', $accountIds)
            ->whereNotNull('sepa_exported_at')
            ->whereNotNull('mandate_reference')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['account_id', 'mandate_reference'])
            ->mapWithKeys(fn (Contribution $item): array => [$item->account_id.'|'.$item->mandate_reference => true]);
        $openContributions = Contribution::query()
            ->whereIn('account_id', $accountIds)
            ->where('payment_method', 'SEPA-Lastschrift')
            ->where('status', 'open')
            ->orderBy('due_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'account_id', 'due_date'])
            ->groupBy('account_id');
        foreach ($contributions as $contribution) {
            $member = $contribution->account->member;
            $key = $member->id.'|'.$member->mandate_reference;
            $alreadyUsed = $previouslyUsed->has($contribution->account_id.'|'.$member->mandate_reference) || isset($seen[$key]);
            if (($member->mandate_type ?? 'recurring') === 'one_off') {
                if ($alreadyUsed) {
                    throw ValidationException::withMessages(['ids' => 'Das einmalige Mandat '.$member->mandate_reference.' wurde bereits verwendet.']);
                }
                $result[$contribution->id] = 'OOFF';
            } elseif (! $alreadyUsed) {
                $result[$contribution->id] = 'FRST';
            } elseif ($this->isFinal($contribution, $openContributions->get($contribution->account_id, collect()))) {
                $result[$contribution->id] = 'FNAL';
            } else {
                $result[$contribution->id] = 'RCUR';
            }
            $seen[$key] = true;
        }

        return $result;
    }

    /** @param Collection<int, Contribution> $openContributions */
    private function isFinal(Contribution $contribution, Collection $openContributions): bool
    {
        $member = $contribution->account->member;
        if ($member->left_at === null || $member->left_at->isAfter($contribution->period_end)) {
            return false;
        }

        return ! $openContributions->contains(fn (Contribution $candidate): bool => $candidate->id !== $contribution->id
            && ($candidate->due_date->isAfter($contribution->due_date)
                || ($candidate->due_date->isSameDay($contribution->due_date) && $candidate->id > $contribution->id)));
    }

    /** @param Collection<int, Contribution> $contributions
     * @param  array<int, string>  $sequences
     * @param  array<string, mixed>  $club
     */
    private function xml(Collection $contributions, array $sequences, array $club, string $collectionDate, string $messageId): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $root = $document->createElementNS(self::NS, 'Document');
        $document->appendChild($root);
        $initiation = $this->add($document, $root, 'CstmrDrctDbtInitn');
        $header = $this->add($document, $initiation, 'GrpHdr');
        $this->add($document, $header, 'MsgId', $messageId);
        $this->add($document, $header, 'CreDtTm', now()->toIso8601String());
        $this->add($document, $header, 'NbOfTxs', (string) $contributions->count());
        $this->add($document, $header, 'CtrlSum', $this->decimal($contributions->sum(fn (Contribution $item): int => $item->remainingCents())));
        $party = $this->add($document, $header, 'InitgPty');
        $this->add($document, $party, 'Nm', $this->text((string) $club['name'], 70));

        foreach ($contributions->groupBy(fn (Contribution $item): string => $sequences[$item->id]) as $sequence => $group) {
            $this->paymentInformation($document, $initiation, $group, $club, $collectionDate, $messageId, $sequence);
        }

        return (string) $document->saveXML();
    }

    /** @param Collection<int, Contribution> $contributions
     * @param  array<string, mixed>  $club
     */
    private function paymentInformation(DOMDocument $document, DOMElement $initiation, Collection $contributions, array $club, string $collectionDate, string $messageId, string $sequence): void
    {
        $payment = $this->add($document, $initiation, 'PmtInf');
        $this->add($document, $payment, 'PmtInfId', $messageId.'-'.$sequence);
        $this->add($document, $payment, 'PmtMtd', 'DD');
        $this->add($document, $payment, 'BtchBookg', 'true');
        $this->add($document, $payment, 'NbOfTxs', (string) $contributions->count());
        $this->add($document, $payment, 'CtrlSum', $this->decimal($contributions->sum(fn (Contribution $item): int => $item->remainingCents())));
        $type = $this->add($document, $payment, 'PmtTpInf');
        $service = $this->add($document, $type, 'SvcLvl');
        $this->add($document, $service, 'Cd', 'SEPA');
        $instrument = $this->add($document, $type, 'LclInstrm');
        $this->add($document, $instrument, 'Cd', 'CORE');
        $this->add($document, $type, 'SeqTp', $sequence);
        $collection = $this->add($document, $payment, 'ReqdColltnDt');
        $this->add($document, $collection, 'Dt', $collectionDate);
        $creditor = $this->add($document, $payment, 'Cdtr');
        $this->add($document, $creditor, 'Nm', $this->text((string) $club['name'], 70));
        $creditorAccount = $this->add($document, $payment, 'CdtrAcct');
        $creditorAccountId = $this->add($document, $creditorAccount, 'Id');
        $this->add($document, $creditorAccountId, 'IBAN', strtoupper(str_replace(' ', '', (string) $club['iban'])));
        $creditorAgent = $this->add($document, $payment, 'CdtrAgt');
        $institution = $this->add($document, $creditorAgent, 'FinInstnId');
        if (! empty($club['bic'])) {
            $this->add($document, $institution, 'BICFI', strtoupper(str_replace(' ', '', (string) $club['bic'])));
        } else {
            $other = $this->add($document, $institution, 'Othr');
            $this->add($document, $other, 'Id', 'NOTPROVIDED');
        }
        $this->add($document, $payment, 'ChrgBr', 'SLEV');
        $scheme = $this->add($document, $payment, 'CdtrSchmeId');
        $schemeId = $this->add($document, $scheme, 'Id');
        $private = $this->add($document, $schemeId, 'PrvtId');
        $other = $this->add($document, $private, 'Othr');
        $this->add($document, $other, 'Id', strtoupper(str_replace(' ', '', (string) $club['creditor_id'])));
        $schemeName = $this->add($document, $other, 'SchmeNm');
        $this->add($document, $schemeName, 'Prtry', 'SEPA');

        foreach ($contributions as $contribution) {
            $this->transaction($document, $payment, $contribution);
        }
    }

    private function transaction(DOMDocument $document, DOMElement $payment, Contribution $contribution): void
    {
        $member = $contribution->account->member;
        $transaction = $this->add($document, $payment, 'DrctDbtTxInf');
        $paymentId = $this->add($document, $transaction, 'PmtId');
        $this->add($document, $paymentId, 'EndToEndId', $this->reference($contribution->payment_reference ?? $contribution->invoice_number ?? 'BEITRAG-'.$contribution->id));
        $amount = $this->add($document, $transaction, 'InstdAmt', $this->decimal($contribution->remainingCents()));
        $amount->setAttribute('Ccy', 'EUR');
        $debit = $this->add($document, $transaction, 'DrctDbtTx');
        $mandate = $this->add($document, $debit, 'MndtRltdInf');
        $this->add($document, $mandate, 'MndtId', $this->reference((string) $member->mandate_reference));
        $this->add($document, $mandate, 'DtOfSgntr', $member->mandate_signed_at->format('Y-m-d'));
        $debtorAgent = $this->add($document, $transaction, 'DbtrAgt');
        $debtorInstitution = $this->add($document, $debtorAgent, 'FinInstnId');
        $debtorOther = $this->add($document, $debtorInstitution, 'Othr');
        $this->add($document, $debtorOther, 'Id', 'NOTPROVIDED');
        $debtor = $this->add($document, $transaction, 'Dbtr');
        $accountHolder = trim(($member->account_holder_first_name ?? '').' '.($member->account_holder_last_name ?? ''));
        $this->add($document, $debtor, 'Nm', $this->text($accountHolder ?: $member->first_name.' '.$member->last_name, 70));
        $debtorAccount = $this->add($document, $transaction, 'DbtrAcct');
        $debtorAccountId = $this->add($document, $debtorAccount, 'Id');
        $this->add($document, $debtorAccountId, 'IBAN', strtoupper(str_replace(' ', '', (string) $member->iban)));
        $remittance = $this->add($document, $transaction, 'RmtInf');
        $this->add($document, $remittance, 'Ustrd', $this->text($contribution->description.' · '.$contribution->payment_reference, 140));
    }

    private function add(DOMDocument $document, DOMElement $parent, string $name, ?string $value = null): DOMElement
    {
        $element = $document->createElementNS(self::NS, $name);
        if ($value !== null) {
            $element->appendChild($document->createTextNode($value));
        }
        $parent->appendChild($element);

        return $element;
    }

    private function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function text(string $value, int $length): string
    {
        $value = preg_replace('/[^A-Za-z0-9ÄÖÜäöüß .,:+?\/-]/u', ' ', trim($value)) ?? trim($value);

        return mb_substr(preg_replace('/\s+/', ' ', $value) ?? $value, 0, $length);
    }

    private function reference(string $value): string
    {
        $value = $this->text($value, 35);
        $value = trim(preg_replace('#/{2,}#', '/', $value) ?? $value, '/');

        return $value === '' ? 'NOTPROVIDED' : $value;
    }
}
