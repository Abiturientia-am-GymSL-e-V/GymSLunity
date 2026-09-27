<?php

namespace App\Finance;

use App\Models\ClubSetting;
use App\Models\FinanceInvoice;
use App\Models\User;
use App\Payments\SepaDirectDebit;
use App\Payments\SepaXmlValidator;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

final class FinanceSepaDirectDebit
{
    public function __construct(private readonly SepaXmlValidator $validator) {}

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
            abort_unless($actor->fresh()?->can('view-finance'), 403);
            $invoices = FinanceInvoice::query()->whereKey($ids)->orderBy('due_date')->orderBy('id')->lockForUpdate()->get();
            if ($invoices->count() !== count($ids)) {
                throw ValidationException::withMessages(['ids' => 'Mindestens eine Rechnung wurde nicht gefunden.']);
            }

            $cancellations = FinanceInvoice::query()
                ->whereIn('original_invoice_id', $invoices->pluck('id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'original_invoice_id', 'total_cents'])
                ->groupBy('original_invoice_id');
            $remaining = [];
            foreach ($invoices as $invoice) {
                $remaining[$invoice->id] = max(0, $invoice->total_cents - (int) $cancellations->get($invoice->id, collect())->sum('total_cents'));
                $payment = $invoice->snapshot['payment'] ?? [];
                if ($invoice->document_type !== 'invoice' || $invoice->status !== 'open' || $invoice->payment_method !== 'sepa_direct_debit'
                    || $invoice->sepa_exported_at !== null || $remaining[$invoice->id] <= 0 || ! is_array($payment)
                    || empty($payment['debtor_iban']) || empty($payment['mandate_reference']) || empty($payment['mandate_signed_at'])) {
                    throw ValidationException::withMessages(['ids' => 'Die Auswahl enthält nicht einziehbare oder unvollständige Rechnungen.']);
                }
            }

            $eligible = $invoices->filter(fn (FinanceInvoice $invoice): bool => ! $invoice->due_date->isAfter($collectionDate))->values();
            if ($eligible->isEmpty()) {
                throw ValidationException::withMessages(['collection_date' => 'Für dieses Einzugsdatum sind noch keine ausgewählten Rechnungen freigegeben.']);
            }

            $sequences = $this->sequences($eligible);
            $uuid = (string) Str::uuid();
            $messageId = 'GYMSL-RW-'.now()->format('YmdHis').'-'.substr(str_replace('-', '', $uuid), 0, 8);
            $xml = $this->xml($eligible, $remaining, $sequences, $club, $collectionDate, $messageId);
            $this->validator->validate($xml);

            DB::table('sepa_exports')->insert([
                'uuid' => $uuid,
                'actor_id' => $actor->id,
                'actor_name' => $actor->name,
                'message_id' => $messageId,
                'transaction_count' => $eligible->count(),
                'total_cents' => $eligible->sum(fn (FinanceInvoice $invoice): int => $remaining[$invoice->id]),
                'collection_date' => $collectionDate,
                'content_hash' => hash('sha256', $xml),
                'created_at' => now(),
            ]);
            foreach ($eligible as $invoice) {
                DB::table('finance_sepa_export_items')->insert([
                    'sepa_export_uuid' => $uuid,
                    'finance_invoice_id' => $invoice->id,
                    'amount_cents' => $remaining[$invoice->id],
                    'mandate_sequence' => $sequences[$invoice->id],
                    'status' => 'exported',
                    'created_at' => now(),
                ]);
                $invoice->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'paid_by' => $actor->id,
                    'paid_by_name' => $actor->name,
                    'sepa_exported_at' => now(),
                    'sepa_export_uuid' => $uuid,
                    'sepa_collection_date' => $collectionDate,
                    'mandate_sequence' => $sequences[$invoice->id],
                ]);
            }

            return $xml;
        }, attempts: 3);
    }

    public function reverse(string $uuid, string $reason, User $actor): int
    {
        return DB::transaction(function () use ($uuid, $reason, $actor): int {
            abort_unless($actor->fresh()?->can('view-finance'), 403);
            $export = DB::table('sepa_exports')->where('uuid', $uuid)->lockForUpdate()->first();
            if (! $export) {
                throw ValidationException::withMessages(['export' => 'Der SEPA-Export wurde nicht gefunden.']);
            }
            $items = DB::table('finance_sepa_export_items')
                ->where('sepa_export_uuid', $uuid)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['export' => 'Der SEPA-Export gehört nicht zum Rechnungswesen.']);
            }
            if ($export->reverted_at !== null) {
                return 0;
            }
            if ($items->contains(fn (object $item): bool => $item->status === 'returned')) {
                throw ValidationException::withMessages(['export' => 'Ein Export mit bereits verbuchten Rücklastschriften kann nicht vollständig zurückgesetzt werden.']);
            }

            $invoices = FinanceInvoice::query()
                ->whereKey($items->pluck('finance_invoice_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            foreach ($items as $item) {
                $invoice = $invoices->get($item->finance_invoice_id);
                if (! $invoice || $invoice->sepa_export_uuid !== $uuid) {
                    throw ValidationException::withMessages(['export' => 'Mindestens eine Rechnung wurde zwischenzeitlich verändert.']);
                }
                $invoice->update([
                    'status' => $invoice->status === 'cancelled' ? 'cancelled' : 'open',
                    'paid_at' => null,
                    'paid_by' => null,
                    'paid_by_name' => null,
                    'sepa_exported_at' => null,
                    'sepa_export_uuid' => null,
                    'sepa_collection_date' => null,
                    'mandate_sequence' => null,
                ]);
            }
            DB::table('finance_sepa_export_items')->where('sepa_export_uuid', $uuid)->update(['status' => 'reverted']);
            DB::table('sepa_exports')->where('uuid', $uuid)->update([
                'reverted_at' => now(),
                'reverted_by' => $actor->id,
                'reverted_by_name' => $actor->name,
                'reversal_reason' => trim($reason),
            ]);

            return $items->count();
        }, attempts: 3);
    }

    /**
     * @param  Collection<int, FinanceInvoice>  $invoices
     * @return array<int, 'FRST'|'OOFF'|'RCUR'>
     */
    private function sequences(Collection $invoices): array
    {
        $usedInvoiceIds = DB::table('finance_sepa_export_items')
            ->whereIn('status', ['exported', 'returned'])
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('finance_invoice_id');
        $used = FinanceInvoice::query()
            ->whereKey($usedInvoiceIds)
            ->orderBy('id')
            ->get(['id', 'snapshot'])
            ->mapWithKeys(function (FinanceInvoice $invoice): array {
                $reference = $invoice->snapshot['payment']['mandate_reference'] ?? null;

                return is_string($reference) && $reference !== '' ? [$reference => true] : [];
            });
        $sequences = [];
        foreach ($invoices as $invoice) {
            $reference = $this->mandateReference($invoice);
            if ($this->mandateType($invoice) === 'one_off') {
                if ($used->has($reference)) {
                    throw ValidationException::withMessages(['ids' => 'Das einmalige Mandat '.$reference.' wurde bereits verwendet.']);
                }
                $sequences[$invoice->id] = 'OOFF';
            } else {
                $sequences[$invoice->id] = $used->has($reference) ? 'RCUR' : 'FRST';
            }
            $used->put($reference, true);
        }

        return $sequences;
    }

    /**
     * @param  Collection<int, FinanceInvoice>  $invoices
     * @param  array<int, int>  $remaining
     * @param  array<int, 'FRST'|'OOFF'|'RCUR'>  $sequences
     * @param  array<string, mixed>  $club
     */
    private function xml(Collection $invoices, array $remaining, array $sequences, array $club, string $collectionDate, string $messageId): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $root = $document->createElementNS(SepaDirectDebit::NS, 'Document');
        $document->appendChild($root);
        $initiation = $this->add($document, $root, 'CstmrDrctDbtInitn');
        $header = $this->add($document, $initiation, 'GrpHdr');
        $this->add($document, $header, 'MsgId', $messageId);
        $this->add($document, $header, 'CreDtTm', now()->toIso8601String());
        $this->add($document, $header, 'NbOfTxs', (string) $invoices->count());
        $this->add($document, $header, 'CtrlSum', $this->decimal($invoices->sum(fn (FinanceInvoice $invoice): int => $remaining[$invoice->id])));
        $party = $this->add($document, $header, 'InitgPty');
        $this->add($document, $party, 'Nm', $this->text((string) $club['name'], 70));

        foreach ($invoices->groupBy(fn (FinanceInvoice $invoice): string => $sequences[$invoice->id]) as $sequence => $group) {
            $this->paymentInformation($document, $initiation, $group, $remaining, $club, $collectionDate, $messageId, $sequence);
        }

        return (string) $document->saveXML();
    }

    /**
     * @param  Collection<int, FinanceInvoice>  $invoices
     * @param  array<int, int>  $remaining
     * @param  array<string, mixed>  $club
     */
    private function paymentInformation(DOMDocument $document, DOMElement $initiation, Collection $invoices, array $remaining, array $club, string $collectionDate, string $messageId, string $sequence): void
    {
        $payment = $this->add($document, $initiation, 'PmtInf');
        $this->add($document, $payment, 'PmtInfId', $messageId.'-'.$sequence);
        $this->add($document, $payment, 'PmtMtd', 'DD');
        $this->add($document, $payment, 'BtchBookg', 'true');
        $this->add($document, $payment, 'NbOfTxs', (string) $invoices->count());
        $this->add($document, $payment, 'CtrlSum', $this->decimal($invoices->sum(fn (FinanceInvoice $invoice): int => $remaining[$invoice->id])));
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

        foreach ($invoices as $invoice) {
            $this->transaction($document, $payment, $invoice, $remaining[$invoice->id]);
        }
    }

    private function transaction(DOMDocument $document, DOMElement $payment, FinanceInvoice $invoice, int $amountCents): void
    {
        $details = $invoice->snapshot['payment'];
        $transaction = $this->add($document, $payment, 'DrctDbtTxInf');
        $paymentId = $this->add($document, $transaction, 'PmtId');
        $this->add($document, $paymentId, 'EndToEndId', $this->reference($invoice->invoice_number));
        $amount = $this->add($document, $transaction, 'InstdAmt', $this->decimal($amountCents));
        $amount->setAttribute('Ccy', 'EUR');
        $debit = $this->add($document, $transaction, 'DrctDbtTx');
        $mandate = $this->add($document, $debit, 'MndtRltdInf');
        $this->add($document, $mandate, 'MndtId', $this->reference((string) $details['mandate_reference']));
        $this->add($document, $mandate, 'DtOfSgntr', (string) $details['mandate_signed_at']);
        $debtorAgent = $this->add($document, $transaction, 'DbtrAgt');
        $debtorInstitution = $this->add($document, $debtorAgent, 'FinInstnId');
        $debtorOther = $this->add($document, $debtorInstitution, 'Othr');
        $this->add($document, $debtorOther, 'Id', 'NOTPROVIDED');
        $debtor = $this->add($document, $transaction, 'Dbtr');
        $this->add($document, $debtor, 'Nm', $this->text($invoice->recipient_name, 70));
        $debtorAccount = $this->add($document, $transaction, 'DbtrAcct');
        $debtorAccountId = $this->add($document, $debtorAccount, 'Id');
        $this->add($document, $debtorAccountId, 'IBAN', strtoupper(str_replace(' ', '', (string) $details['debtor_iban'])));
        $remittance = $this->add($document, $transaction, 'RmtInf');
        $this->add($document, $remittance, 'Ustrd', $this->text('Rechnung '.$invoice->invoice_number, 140));
    }

    private function add(DOMDocument $document, DOMElement $parent, string $name, ?string $value = null): DOMElement
    {
        $element = $document->createElementNS(SepaDirectDebit::NS, $name);
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

    /** @return non-empty-string */
    private function mandateReference(FinanceInvoice $invoice): string
    {
        $reference = $invoice->snapshot['payment']['mandate_reference'] ?? null;
        if (! is_string($reference) || trim($reference) === '') {
            throw new LogicException('Die archivierte Rechnung enthält keine gültige Mandatsreferenz.');
        }

        return $reference;
    }

    /** @return 'one_off'|'recurring' */
    private function mandateType(FinanceInvoice $invoice): string
    {
        $type = $invoice->snapshot['payment']['mandate_type'] ?? $invoice->mandate_type;

        return $type === 'one_off' ? 'one_off' : 'recurring';
    }
}
