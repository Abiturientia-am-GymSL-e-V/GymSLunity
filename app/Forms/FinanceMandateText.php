<?php

declare(strict_types=1);

namespace App\Forms;

use App\Configuration\ClubData;

final class FinanceMandateText
{
    public const DEFAULT = 'Ich ermächtige {{verein.name}}, Zahlungen von meinem Konto mittels Lastschrift einzuziehen. Zugleich weise ich mein Kreditinstitut an, die von {{verein.name}} auf mein Konto gezogenen Lastschriften einzulösen. Hinweis: Ich kann innerhalb von acht Wochen, beginnend mit dem Belastungsdatum, die Erstattung des belasteten Betrags verlangen. Es gelten dabei die mit meinem Kreditinstitut vereinbarten Bedingungen.';

    /** @return list<string> */
    public static function placeholders(): array
    {
        return [ClubData::placeholder('name'), ClubData::placeholder('creditor_id')];
    }

    /** @param array<string, mixed> $club */
    public static function render(string $template, array $club): string
    {
        return strtr($template, [
            ClubData::placeholder('name') => (string) ($club['name'] ?? ''),
            ClubData::placeholder('creditor_id') => (string) ($club['creditor_id'] ?? ''),
        ]);
    }
}
