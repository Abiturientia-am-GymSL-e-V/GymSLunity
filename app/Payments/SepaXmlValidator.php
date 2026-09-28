<?php

declare(strict_types=1);

namespace App\Payments;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Validation\ValidationException;

final class SepaXmlValidator
{
    public function validate(string $xml): void
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded || $errors !== []) {
            $this->fail('Das erzeugte XML ist nicht wohlgeformt.');
        }
        if ($document->documentElement?->namespaceURI !== SepaDirectDebit::NS) {
            $this->fail('Der Export verwendet nicht das erwartete PAIN.008.001.08-Format.');
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('p', SepaDirectDebit::NS);
        $transactions = $xpath->query('//p:DrctDbtTxInf');
        $headerCount = $xpath->evaluate('string(/p:Document/p:CstmrDrctDbtInitn/p:GrpHdr/p:NbOfTxs)');
        $headerSum = $xpath->evaluate('string(/p:Document/p:CstmrDrctDbtInitn/p:GrpHdr/p:CtrlSum)');
        if (! $transactions || $transactions->length === 0 || (int) $headerCount !== $transactions->length) {
            $this->fail('Die Transaktionsanzahl des SEPA-Exports ist inkonsistent.');
        }
        $calculated = 0.0;
        foreach ($xpath->query('//p:DrctDbtTxInf/p:InstdAmt') ?: [] as $amount) {
            if (! $amount instanceof DOMElement || $amount->getAttribute('Ccy') !== 'EUR' || ! is_numeric($amount->textContent) || (float) $amount->textContent <= 0) {
                $this->fail('Der SEPA-Export enthält einen ungültigen Euro-Betrag.');
            }
            $calculated += (float) $amount->textContent;
        }
        if (abs((float) $headerSum - $calculated) > 0.001) {
            $this->fail('Die Kontrollsumme des SEPA-Exports ist inkonsistent.');
        }
        foreach ($xpath->query('//p:PmtInf') ?: [] as $payment) {
            if (! $payment instanceof DOMNode) {
                $this->fail('Der SEPA-Export enthält eine ungültige Zahlungsgruppe.');
            }
            $sequence = $xpath->evaluate('string(p:PmtTpInf/p:SeqTp)', $payment);
            $date = $xpath->evaluate('string(p:ReqdColltnDt/p:Dt)', $payment);
            $count = (int) $xpath->evaluate('string(p:NbOfTxs)', $payment);
            $actual = (int) $xpath->evaluate('count(p:DrctDbtTxInf)', $payment);
            if (! in_array($sequence, ['FRST', 'RCUR', 'OOFF', 'FNAL'], true) || $count !== $actual || ! $this->date($date)) {
                $this->fail('Eine SEPA-Zahlungsgruppe ist formal ungültig.');
            }
        }
        foreach (['//p:EndToEndId', '//p:MndtId', '//p:DbtrAcct/p:Id/p:IBAN'] as $expression) {
            foreach ($xpath->query($expression) ?: [] as $node) {
                if (! $node instanceof DOMNode) {
                    $this->fail('Der SEPA-Export enthält einen ungültigen XML-Knoten.');
                }
                $value = trim($node->textContent);
                if ($value === '' || str_starts_with($value, '/') || str_ends_with($value, '/') || str_contains($value, '//')) {
                    $this->fail('Der SEPA-Export enthält eine ungültige Referenz oder Kontokennung.');
                }
            }
        }
        foreach (['MsgId', 'PmtInfId', 'EndToEndId'] as $name) {
            $values = [];
            foreach ($xpath->query('//p:'.$name) ?: [] as $node) {
                if (! $node instanceof DOMNode) {
                    $this->fail('Der SEPA-Export enthält einen ungültigen XML-Knoten.');
                }
                if (isset($values[$node->textContent])) {
                    $this->fail($name.' ist im SEPA-Export nicht eindeutig.');
                }
                $values[$node->textContent] = true;
            }
        }
    }

    private function date(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['ids' => $message]);
    }
}
