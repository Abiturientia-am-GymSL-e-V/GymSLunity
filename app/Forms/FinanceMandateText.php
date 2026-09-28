<?php

declare(strict_types=1);

namespace App\Forms;

final class FinanceMandateText
{
    public const DEFAULT = 'Ich ermächtige {{verein.name}}, Zahlungen von meinem Konto mittels Lastschrift einzuziehen. Zugleich weise ich mein Kreditinstitut an, die von {{verein.name}} auf mein Konto gezogenen Lastschriften einzulösen. Hinweis: Ich kann innerhalb von acht Wochen, beginnend mit dem Belastungsdatum, die Erstattung des belasteten Betrags verlangen. Es gelten dabei die mit meinem Kreditinstitut vereinbarten Bedingungen.';

    /** @param array<string, mixed> $club */
    public static function render(string $template, array $club): string
    {
        return strtr($template, [
            '{{verein.name}}' => (string) ($club['name'] ?? ''),
            '{{verein.glaeubiger_id}}' => (string) ($club['creditor_id'] ?? ''),
        ]);
    }
}
