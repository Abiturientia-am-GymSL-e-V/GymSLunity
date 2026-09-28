<?php

declare(strict_types=1);

namespace App\Inventory;

final class InventoryOptions
{
    /** @return array<string, string> */
    public static function categories(): array
    {
        return [
            'sports_equipment' => 'Sportgerät',
            'it' => 'IT & Elektronik',
            'office' => 'Büroausstattung',
            'facility' => 'Gebäude & Ausstattung',
            'event' => 'Veranstaltungsausstattung',
            'vehicle' => 'Fahrzeug',
            'other' => 'Sonstiges',
        ];
    }

    /** @return array<string, string> */
    public static function acquisitionTypes(): array
    {
        return [
            'purchase' => 'Kauf',
            'donation' => 'Sachspende',
            'manufacture' => 'Herstellung',
            'transfer' => 'Übernahme/Einlage',
            'other' => 'Sonstiger Zugang',
        ];
    }

    /** @return array<string, string> */
    public static function depreciationMethods(): array
    {
        return [
            'linear' => 'Lineare Abschreibung',
            'immediate' => 'Sofortabschreibung',
            'none' => 'Keine Abschreibung',
        ];
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            'active' => 'Im Bestand',
            'sold' => 'Verkauft',
            'lost' => 'Verlust',
            'disposed' => 'Entsorgt',
        ];
    }
}
