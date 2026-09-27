<?php

namespace App\Finance;

use DOMDocument;
use DOMElement;

final class XRechnung
{
    private const UBL = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    private const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    /** @param array<string, mixed> $invoice */
    public function create(array $invoice): string
    {
        $isCancellation = ($invoice['document_type'] ?? 'invoice') === 'cancellation';
        $sign = $isCancellation ? -1 : 1;
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $root = $document->createElementNS(self::UBL, 'Invoice');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::CBC);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::CAC);
        $document->appendChild($root);

        $this->cbc($document, $root, 'CustomizationID', 'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0');
        $this->cbc($document, $root, 'ProfileID', 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0');
        $this->cbc($document, $root, 'ID', $invoice['invoice_number']);
        $this->cbc($document, $root, 'IssueDate', $invoice['issue_date']);
        $this->cbc($document, $root, 'DueDate', $invoice['due_date']);
        $this->cbc($document, $root, 'InvoiceTypeCode', $isCancellation ? '384' : '380');
        if ($isCancellation) {
            $scope = ($invoice['cancellation_scope'] ?? 'full') === 'partial'
                ? 'Teilstornierung ausgewählter Positionen aus der Rechnung '
                : 'Vollständige Stornierung der Rechnung ';
            $this->cbc(
                $document,
                $root,
                'Note',
                $scope.$invoice['original_invoice']['invoice_number'].' vom '.$invoice['original_invoice']['issue_date'].'. Grund: '.$invoice['cancellation_reason'],
            );
        } elseif ($invoice['notes'] !== '') {
            $this->cbc($document, $root, 'Note', $invoice['notes']);
        }
        $this->cbc($document, $root, 'DocumentCurrencyCode', $invoice['currency']);
        $this->cbc($document, $root, 'BuyerReference', $invoice['buyer_reference']);

        $period = $this->cac($document, $root, 'InvoicePeriod');
        $this->cbc($document, $period, 'StartDate', $invoice['service_date']);
        $this->cbc($document, $period, 'EndDate', $invoice['service_date']);

        if ($isCancellation) {
            $reference = $this->cac($document, $root, 'BillingReference');
            $invoiceReference = $this->cac($document, $reference, 'InvoiceDocumentReference');
            $this->cbc($document, $invoiceReference, 'ID', $invoice['original_invoice']['invoice_number']);
            $this->cbc($document, $invoiceReference, 'IssueDate', $invoice['original_invoice']['issue_date']);
        }

        $this->party($document, $root, 'AccountingSupplierParty', $invoice['seller'], true);
        $this->party($document, $root, 'AccountingCustomerParty', $invoice['buyer'], false);
        if (! $isCancellation) {
            $this->payment($document, $root, $invoice);
        }

        $taxGroups = [];
        foreach ($invoice['items'] as $item) {
            $key = $item['vat_category'].'-'.$item['vat_rate'];
            $taxGroups[$key] ??= ['taxable_cents' => 0, 'tax_cents' => 0, ...$item];
            $taxGroups[$key]['taxable_cents'] += $item['net_cents'];
            $taxGroups[$key]['tax_cents'] += $item['tax_cents'];
        }
        $taxTotal = $this->cac($document, $root, 'TaxTotal');
        $this->amount($document, $taxTotal, 'TaxAmount', $sign * $invoice['tax_cents'], $invoice['currency']);
        foreach ($taxGroups as $group) {
            $subtotal = $this->cac($document, $taxTotal, 'TaxSubtotal');
            $this->amount($document, $subtotal, 'TaxableAmount', $sign * $group['taxable_cents'], $invoice['currency']);
            $this->amount($document, $subtotal, 'TaxAmount', $sign * $group['tax_cents'], $invoice['currency']);
            $category = $this->cac($document, $subtotal, 'TaxCategory');
            $this->cbc($document, $category, 'ID', $group['vat_category']);
            $this->cbc($document, $category, 'Percent', (string) $group['vat_rate']);
            if ($group['vat_category'] === 'E') {
                $this->cbc($document, $category, 'TaxExemptionReason', $group['tax_exemption_reason']);
            }
            $scheme = $this->cac($document, $category, 'TaxScheme');
            $this->cbc($document, $scheme, 'ID', 'VAT');
        }

        $totals = $this->cac($document, $root, 'LegalMonetaryTotal');
        $this->amount($document, $totals, 'LineExtensionAmount', $sign * $invoice['subtotal_cents'], $invoice['currency']);
        $this->amount($document, $totals, 'TaxExclusiveAmount', $sign * $invoice['subtotal_cents'], $invoice['currency']);
        $this->amount($document, $totals, 'TaxInclusiveAmount', $sign * $invoice['total_cents'], $invoice['currency']);
        $this->amount($document, $totals, 'PayableAmount', $sign * $invoice['total_cents'], $invoice['currency']);

        foreach ($invoice['items'] as $index => $item) {
            $line = $this->cac($document, $root, 'InvoiceLine');
            $this->cbc($document, $line, 'ID', (string) ($index + 1));
            $quantity = $this->cbc($document, $line, 'InvoicedQuantity', ($isCancellation ? '-' : '').$item['quantity']);
            $quantity->setAttribute('unitCode', $item['unit_code']);
            $this->amount($document, $line, 'LineExtensionAmount', $sign * $item['net_cents'], $invoice['currency']);
            $linePeriod = $this->cac($document, $line, 'InvoicePeriod');
            $this->cbc($document, $linePeriod, 'StartDate', $invoice['service_date']);
            $this->cbc($document, $linePeriod, 'EndDate', $invoice['service_date']);
            $itemNode = $this->cac($document, $line, 'Item');
            $this->cbc($document, $itemNode, 'Name', $item['description']);
            $category = $this->cac($document, $itemNode, 'ClassifiedTaxCategory');
            $this->cbc($document, $category, 'ID', $item['vat_category']);
            $this->cbc($document, $category, 'Percent', (string) $item['vat_rate']);
            $scheme = $this->cac($document, $category, 'TaxScheme');
            $this->cbc($document, $scheme, 'ID', 'VAT');
            $price = $this->cac($document, $line, 'Price');
            $this->decimalAmount(
                $document,
                $price,
                'PriceAmount',
                (string) ($item['unit_price_net'] ?? number_format($item['unit_price_cents'] / 100, 2, '.', '')),
                $invoice['currency'],
            );
        }

        return (string) $document->saveXML();
    }

    /** @param array<string, mixed> $party */
    private function party(DOMDocument $document, DOMElement $root, string $name, array $party, bool $seller): void
    {
        $container = $this->cac($document, $root, $name);
        $node = $this->cac($document, $container, 'Party');
        $endpoint = $this->cbc($document, $node, 'EndpointID', $party['email']);
        $endpoint->setAttribute('schemeID', 'EM');
        if ($seller && $party['creditor_id'] !== '') {
            $identification = $this->cac($document, $node, 'PartyIdentification');
            $creditorId = $this->cbc($document, $identification, 'ID', $party['creditor_id']);
            $creditorId->setAttribute('schemeID', 'SEPA');
        }
        $partyName = $this->cac($document, $node, 'PartyName');
        $this->cbc($document, $partyName, 'Name', $party['name']);
        $address = $this->cac($document, $node, 'PostalAddress');
        $this->cbc($document, $address, 'StreetName', $party['street']);
        $this->cbc($document, $address, 'CityName', $party['city']);
        $this->cbc($document, $address, 'PostalZone', $party['postal_code']);
        $country = $this->cac($document, $address, 'Country');
        $this->cbc($document, $country, 'IdentificationCode', $party['country']);
        if ($seller) {
            if ($party['vat_id'] !== '') {
                $tax = $this->cac($document, $node, 'PartyTaxScheme');
                $this->cbc($document, $tax, 'CompanyID', $party['vat_id']);
                $scheme = $this->cac($document, $tax, 'TaxScheme');
                $this->cbc($document, $scheme, 'ID', 'VAT');
            }
            if ($party['tax_number'] !== '') {
                $tax = $this->cac($document, $node, 'PartyTaxScheme');
                $company = $this->cbc($document, $tax, 'CompanyID', $party['tax_number']);
                $company->setAttribute('schemeID', 'FC');
                $scheme = $this->cac($document, $tax, 'TaxScheme');
                $this->cbc($document, $scheme, 'ID', 'FC');
            }
        }
        $legal = $this->cac($document, $node, 'PartyLegalEntity');
        $this->cbc($document, $legal, 'RegistrationName', $party['name']);
        if ($seller) {
            $contact = $this->cac($document, $node, 'Contact');
            $this->cbc($document, $contact, 'Name', $party['name']);
            $this->cbc($document, $contact, 'Telephone', $party['phone']);
            $this->cbc($document, $contact, 'ElectronicMail', $party['email']);
        }
    }

    /** @param array<string, mixed> $invoice */
    private function payment(DOMDocument $document, DOMElement $root, array $invoice): void
    {
        $codes = ['bank_transfer' => '58', 'sepa_direct_debit' => '59', 'cash' => '10', 'card' => '48', 'other' => 'ZZZ'];
        $payment = $this->cac($document, $root, 'PaymentMeans');
        $this->cbc($document, $payment, 'PaymentMeansCode', $codes[$invoice['payment_method']]);
        $this->cbc($document, $payment, 'PaymentID', $invoice['invoice_number']);
        if ($invoice['payment_method'] === 'bank_transfer') {
            $account = $this->cac($document, $payment, 'PayeeFinancialAccount');
            $this->cbc($document, $account, 'ID', $invoice['seller']['iban']);
            if ($invoice['seller']['bic'] !== '') {
                $branch = $this->cac($document, $account, 'FinancialInstitutionBranch');
                $this->cbc($document, $branch, 'ID', $invoice['seller']['bic']);
            }
        }
        if ($invoice['payment_method'] === 'sepa_direct_debit') {
            $mandate = $this->cac($document, $payment, 'PaymentMandate');
            $this->cbc($document, $mandate, 'ID', $invoice['payment']['mandate_reference']);
            $account = $this->cac($document, $mandate, 'PayerFinancialAccount');
            $this->cbc($document, $account, 'ID', $invoice['payment']['debtor_iban']);
        }
        $terms = $this->cac($document, $root, 'PaymentTerms');
        $this->cbc(
            $document,
            $terms,
            'Note',
            $invoice['payment_method'] === 'sepa_direct_debit'
                ? 'Einzug ab '.$invoice['due_date'].'.'
                : 'Zahlbar bis '.$invoice['due_date'].'.',
        );
    }

    private function amount(DOMDocument $document, DOMElement $parent, string $name, int $cents, string $currency): DOMElement
    {
        return $this->decimalAmount($document, $parent, $name, number_format($cents / 100, 2, '.', ''), $currency);
    }

    private function decimalAmount(DOMDocument $document, DOMElement $parent, string $name, string $amount, string $currency): DOMElement
    {
        $element = $this->cbc($document, $parent, $name, $amount);
        $element->setAttribute('currencyID', $currency);

        return $element;
    }

    private function cbc(DOMDocument $document, DOMElement $parent, string $name, string $value): DOMElement
    {
        $element = $document->createElementNS(self::CBC, 'cbc:'.$name);
        $element->appendChild($document->createTextNode($value));
        $parent->appendChild($element);

        return $element;
    }

    private function cac(DOMDocument $document, DOMElement $parent, string $name): DOMElement
    {
        $element = $document->createElementNS(self::CAC, 'cac:'.$name);
        $parent->appendChild($element);

        return $element;
    }
}
