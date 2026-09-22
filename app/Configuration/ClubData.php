<?php

namespace App\Configuration;

final class ClubData
{
    /** @return list<array{key: string, label: string, type: string, section: string, required: bool}> */
    public static function fields(): array
    {
        $field = fn (string $key, string $label, string $section, string $type = 'text', bool $required = false): array => compact('key', 'label', 'type', 'section', 'required');

        return [
            $field('name', 'Vollständiger Vereinsname', 'Allgemein', required: true),
            $field('short_name', 'Kurzname / Name in der Navigation', 'Allgemein'),
            $field('founded_at', 'Gründungsdatum', 'Allgemein', 'date'),
            $field('street', 'Straße und Hausnummer', 'Adresse & Kontakt'),
            $field('postal_code', 'Postleitzahl', 'Adresse & Kontakt'),
            $field('city', 'Ort', 'Adresse & Kontakt'),
            $field('country', 'Land', 'Adresse & Kontakt', 'country'),
            $field('email', 'E-Mail-Adresse', 'Adresse & Kontakt', 'email'),
            $field('phone', 'Telefon', 'Adresse & Kontakt', 'tel'),
            $field('website', 'Website', 'Adresse & Kontakt', 'url'),
            $field('register_number', 'Vereinsregisternummer', 'Register & Steuern'),
            $field('register_court', 'Registergericht', 'Register & Steuern'),
            $field('tax_number', 'Steuernummer', 'Register & Steuern'),
            $field('tax_office', 'Finanzamt', 'Register & Steuern'),
            $field('vat_id', 'Umsatzsteuer-ID', 'Register & Steuern'),
            $field('is_nonprofit', 'Gemeinnützig', 'Register & Steuern', 'boolean'),
            $field('nonprofit_purpose', 'Gemeinnütziger Zweck', 'Register & Steuern'),
            $field('account_holder', 'Kontoinhaber', 'Bankverbindung'),
            $field('iban', 'IBAN', 'Bankverbindung'),
            $field('bic', 'BIC', 'Bankverbindung'),
            $field('bank_name', 'Kreditinstitut', 'Bankverbindung'),
            $field('creditor_id', 'SEPA-Gläubiger-ID', 'Bankverbindung'),
        ];
    }
}
