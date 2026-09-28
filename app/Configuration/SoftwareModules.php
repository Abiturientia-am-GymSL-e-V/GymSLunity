<?php

declare(strict_types=1);

namespace App\Configuration;

use App\Models\ClubSetting;

final class SoftwareModules
{
    /** @var array<string, array{label: string, description: string, areas: list<string>}> */
    public const OPTIONAL = [
        'payments' => [
            'label' => 'Beiträge',
            'description' => 'Mitgliedsbeiträge festsetzen, einziehen und verbuchen.',
            'areas' => ['Beitragskonten', 'Rechnungen', 'SEPA', 'Bankimport'],
        ],
        'statistics' => [
            'label' => 'Auswertungen',
            'description' => 'Mitglieder, Finanzen und Datenqualität statistisch auswerten.',
            'areas' => ['Mitglieder', 'Finanzen', 'Datenqualität'],
        ],
        'finance' => [
            'label' => 'Buchhaltung',
            'description' => 'Rechnungen erstellen, versenden und offene Forderungen verwalten.',
            'areas' => ['Rechnungswesen', 'PDF- und XRechnungen', 'Offene Forderungen'],
        ],
        'forms' => [
            'label' => 'Formulare',
            'description' => 'Quittungen und weitere Vereinsformulare erstellen und archivieren.',
            'areas' => ['Quittungen', 'PDF-Dokumente'],
        ],
        'donations' => [
            'label' => 'Spenden',
            'description' => 'Spenden erfassen und Zuwendungsbestätigungen ausstellen.',
            'areas' => ['Spendenbuch', 'Zuwendungsbestätigungen'],
        ],
        'inventory' => [
            'label' => 'Inventar',
            'description' => 'Vereinseigentum, Wertentwicklung und Abgänge dokumentieren.',
            'areas' => ['Inventarisierung', 'Abschreibung', 'Abgänge'],
        ],
        'calendar' => [
            'label' => 'Kalender',
            'description' => 'Vereinstermine planen und Kalender mit Mitgliedern teilen.',
            'areas' => ['Termine', 'Geburtstage', 'Kalender-Abos'],
        ],
        'bookings' => [
            'label' => 'Buchungen',
            'description' => 'Räume, Geräte und andere Ressourcen verwalten und buchbar machen.',
            'areas' => ['Ressourcen', 'Belegung', 'Buchungsanfragen'],
        ],
        'communication' => [
            'label' => 'Kommunikation',
            'description' => 'Personalisierte Serien-E-Mails und Serienbriefe versenden.',
            'areas' => ['Serien-E-Mails', 'Serienbriefe', 'Versandverlauf'],
        ],
    ];

    /** @return array<string, bool> */
    public static function values(?ClubSetting $settings = null): array
    {
        $configured = ($settings ?? ClubSetting::current())->data['software_modules'] ?? [];
        if (! is_array($configured)) {
            $configured = [];
        }

        return collect(array_keys(self::OPTIONAL))->mapWithKeys(
            fn (string $key): array => [$key => ! array_key_exists($key, $configured) || $configured[$key] === true]
        )->all();
    }

    public static function enabled(string $module, ?ClubSetting $settings = null): bool
    {
        return self::values($settings)[$module] ?? false;
    }
}
