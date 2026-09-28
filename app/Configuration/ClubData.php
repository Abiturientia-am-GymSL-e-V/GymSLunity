<?php

declare(strict_types=1);

namespace App\Configuration;

final class ClubData
{
    /** @return list<array{key: string, label: string, type: string, section: string, required: bool, options: array<string, string>, default: string|bool|null}> */
    public static function fields(): array
    {
        $field = fn (string $key, string $label, string $section, string $type = 'text', bool $required = false, array $options = [], string|bool|null $default = null): array => compact('key', 'label', 'type', 'section', 'required', 'options', 'default');

        return [
            $field('name', 'Vollständiger Vereinsname', 'Allgemein', required: true),
            $field('short_name', 'Kurzname / Name in der Navigation', 'Allgemein'),
            $field('form_of_address', 'Anrede in Systemtexten', 'Allgemein', 'select', true, ['du' => 'Du', 'sie' => 'Sie'], 'du'),
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
            $field('account_holder', 'Kontoinhaber', 'Bankverbindung'),
            $field('iban', 'IBAN', 'Bankverbindung'),
            $field('bic', 'BIC', 'Bankverbindung'),
            $field('bank_name', 'Kreditinstitut', 'Bankverbindung'),
            $field('creditor_id', 'SEPA-Gläubiger-ID', 'Bankverbindung'),
        ];
    }
}
