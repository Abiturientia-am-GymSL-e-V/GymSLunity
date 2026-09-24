<?php

namespace App\Payments;

use App\Models\ClubSetting;
use App\Models\Contribution;
use App\Models\User;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SepaDirectDebit
{
    private const NS = 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.02';

    public function __construct(private readonly ContributionLedger $ledger) {}

    /** @param list<int> $ids */
    public function export(array $ids, string $collectionDate, User $actor): string
    {
        $club = ClubSetting::current()->data;
        foreach (['name' => 'Vereinsname', 'iban' => 'Vereins-IBAN', 'creditor_id' => 'SEPA-Gläubiger-ID'] as $key => $label) {
            if (empty($club[$key])) {
                throw ValidationException::withMessages(['ids' => $label.' fehlt in der Vereinskonfiguration.']);
            }
        }

        return DB::transaction(function () use ($ids, $collectionDate, $actor, $club): string {
            $contributions = Contribution::query()->with('account.member')->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
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
            $uuid = (string) Str::uuid();
            $messageId = 'GYMSL-'.now()->format('YmdHis').'-'.substr(str_replace('-', '', $uuid), 0, 8);
            $xml = $this->xml($contributions, $club, $collectionDate, $messageId);
            DB::table('sepa_exports')->insert([
                'uuid' => $uuid, 'actor_id' => $actor->id, 'actor_name' => $actor->name,
                'message_id' => $messageId, 'transaction_count' => $contributions->count(),
                'total_cents' => $contributions->sum(fn (Contribution $item): int => $item->remainingCents()),
                'collection_date' => $collectionDate, 'content_hash' => hash('sha256', $xml), 'created_at' => now(),
            ]);
            foreach ($contributions as $contribution) {
                $this->ledger->payment(
                    $contribution->account->member,
                    $actor,
                    $contribution->remainingCents(),
                    $collectionDate,
                    'SEPA-Lastschrift '.$messageId,
                    $contribution->invoice_number ?: 'BEITRAG-'.$contribution->id,
                    $contribution,
                    'sepa_payment',
                    ['sepa_export_uuid' => $uuid, 'message_id' => $messageId],
                );
            }

            return $xml;
        });
    }

    /** @param Collection<int, Contribution> $contributions
     * @param  array<string, mixed>  $club
     */
    private function xml(Collection $contributions, array $club, string $collectionDate, string $messageId): string
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

        $payment = $this->add($document, $initiation, 'PmtInf');
        $this->add($document, $payment, 'PmtInfId', $messageId.'-P1');
        $this->add($document, $payment, 'PmtMtd', 'DD');
        $this->add($document, $payment, 'BtchBookg', 'true');
        $this->add($document, $payment, 'NbOfTxs', (string) $contributions->count());
        $this->add($document, $payment, 'CtrlSum', $this->decimal($contributions->sum(fn (Contribution $item): int => $item->remainingCents())));
        $type = $this->add($document, $payment, 'PmtTpInf');
        $service = $this->add($document, $type, 'SvcLvl');
        $this->add($document, $service, 'Cd', 'SEPA');
        $instrument = $this->add($document, $type, 'LclInstrm');
        $this->add($document, $instrument, 'Cd', 'CORE');
        $this->add($document, $type, 'SeqTp', 'RCUR');
        $this->add($document, $payment, 'ReqdColltnDt', $collectionDate);
        $creditor = $this->add($document, $payment, 'Cdtr');
        $this->add($document, $creditor, 'Nm', $this->text((string) $club['name'], 70));
        $creditorAccount = $this->add($document, $payment, 'CdtrAcct');
        $creditorAccountId = $this->add($document, $creditorAccount, 'Id');
        $this->add($document, $creditorAccountId, 'IBAN', strtoupper(str_replace(' ', '', (string) $club['iban'])));
        $creditorAgent = $this->add($document, $payment, 'CdtrAgt');
        $institution = $this->add($document, $creditorAgent, 'FinInstnId');
        if (! empty($club['bic'])) {
            $this->add($document, $institution, 'BIC', strtoupper(str_replace(' ', '', (string) $club['bic'])));
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
            $member = $contribution->account->member;
            $transaction = $this->add($document, $payment, 'DrctDbtTxInf');
            $paymentId = $this->add($document, $transaction, 'PmtId');
            $this->add($document, $paymentId, 'EndToEndId', $this->text($contribution->invoice_number ?: 'BEITRAG-'.$contribution->id, 35));
            $amount = $this->add($document, $transaction, 'InstdAmt', $this->decimal($contribution->remainingCents()));
            $amount->setAttribute('Ccy', 'EUR');
            $debit = $this->add($document, $transaction, 'DrctDbtTx');
            $mandate = $this->add($document, $debit, 'MndtRltdInf');
            $this->add($document, $mandate, 'MndtId', $this->text((string) $member->mandate_reference, 35));
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
            $this->add($document, $remittance, 'Ustrd', $this->text($contribution->description.' Mitglied '.$member->member_number, 140));
        }

        return (string) $document->saveXML();
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
}
