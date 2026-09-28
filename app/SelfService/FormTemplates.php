<?php

declare(strict_types=1);

namespace App\SelfService;

use App\Configuration\ClubData;
use App\Configuration\ClubSettings;
use App\Donations\DonationPurposes;
use App\Support\Iban;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class FormTemplates
{
    /** @return array<string, string> */
    public static function defaults(): array
    {
        return [
            'application_text' => 'Hiermit beantrage ich die Mitgliedschaft bei {{verein.name}}. Ich erkenne die Satzung und die geltende Beitragsordnung des Vereins an.',
            'guardian_text' => 'Als sorgeberechtigte Person stimme ich der Mitgliedschaft meines Kindes im Verein {{verein.name}} und der Wahrnehmung der damit verbundenen Rechte und Pflichten zu.',
            'sepa_text' => 'Ich ermächtige {{verein.name}}, Zahlungen von meinem Konto mittels Lastschrift einzuziehen. Zugleich weise ich mein Kreditinstitut an, die von {{verein.name}} auf mein Konto gezogenen Lastschriften einzulösen. Dieses Mandat gilt für wiederkehrende Zahlungen. Gläubiger-Identifikationsnummer: {{verein.creditor_id}}. Hinweis: Ich kann innerhalb von acht Wochen, beginnend mit dem Belastungsdatum, die Erstattung des belasteten Betrages verlangen. Es gelten dabei die mit meinem Kreditinstitut vereinbarten Bedingungen.',
            'receipt_notes' => <<<'TEXT'
Diese Quittung stellt keine Rechnung und keine Zuwendungsbestätigung (gemäß AO) dar!
Sie dient der Bestätigung erhaltener und geleisteter barer oder unbarer Zahlungen und ist gleichzeitig Ein- und Auszahlungsbeleg.

Bei Unsicherheiten keine Quittung ohne Rücksprache mit dem Kassierer ausstellen! Der Zahlungsempfänger übernimmt keine Haftung für mittels Täuschung erlangte Quittungen. Jegliche Fälschung oder nachträgliche Änderung stellt eine Urkundenfälschung gemäß § 267 StGB dar und wird zur Anzeige gebracht!
TEXT,
            'receipt_donation_notes' => <<<'TEXT'
Vereinfachter Spendennachweis (§ 50 Abs. 4 Nr. 2 Buchst. b EStDV)

Bei Spenden bis zu 300 Euro dient dieser Beleg als Zuwendungsbestätigung (Spendenquittung) zur Vorlage bei Ihrem Finanzamt.

Empfänger: {{verein.name}}, {{verein.street}}, {{verein.postal_code}} {{verein.city}}
Art der Zuwendung: Geldzuwendung

{{verein.name}}, {{verein.street}}, {{verein.postal_code}} {{verein.city}}, ist nach dem letzten uns zugegangenen {{verein.tax_privilege_notice}} vom {{verein.tax_privilege_notice_date}} des Finanzamtes {{verein.tax_office}}, Steuernummer {{verein.tax_number}}, gemäß § 5 Abs. 1 Nr. 9 KStG von der Körperschaftsteuer und nach § 3 Nr. 6 GewStG von der Gewerbesteuer befreit.
{{verein.deductible_scope}} an {{verein.name}} sind gemäß § 10b EStG steuerlich absetzbar.
Wir bestätigen, dass die Zuwendung nur für folgende steuerbegünstigte Zwecke im Sinne der §§ 52 bis 54 AO verwendet wird: {{verein.donation_purposes}}.
TEXT,
        ];
    }

    /** @return list<string> */
    public static function placeholders(): array
    {
        return array_values(array_unique([
            ...array_map(fn (array $field): string => '{{verein.'.$field['key'].'}}', ClubData::fields()),
            '{{verein.tax_privilege_notice}}',
            '{{verein.tax_privilege_notice_date}}',
            '{{verein.donation_purposes}}',
            '{{verein.deductible_scope}}',
        ]));
    }

    public static function validate(string $text): void
    {
        preg_match_all('/\{\{.*?\}\}/s', $text, $matches);
        if (array_diff($matches[0], self::placeholders()) !== []) {
            throw ValidationException::withMessages(['templates' => 'Unbekannter Platzhalter. Bitte verwende die angegebenen Vereinsstammdaten.']);
        }
    }

    /** @return array<string, string> */
    public static function rendered(): array
    {
        $data = app(ClubSettings::class)->data();
        $result = [];
        foreach (self::defaults() as $key => $default) {
            $result[$key] = self::renderText((string) ($data[$key] ?? $default), $data);
        }

        return $result;
    }

    /** @param array<string, mixed>|null $data */
    public static function renderText(string $text, ?array $data = null): string
    {
        $data ??= app(ClubSettings::class)->data();
        $replacements = [];
        foreach (ClubData::fields() as $field) {
            $value = (string) ($data[$field['key']] ?? '');
            $replacements['{{verein.'.$field['key'].'}}'] = $field['key'] === 'iban'
                ? Iban::format($value)
                : $value;
        }
        $purposeCodes = is_array($data['donation_purpose_codes'] ?? null) ? $data['donation_purpose_codes'] : [];
        $notice = match ($data['tax_privilege_notice_type'] ?? null) {
            'exemption_notice' => 'Freistellungsbescheid',
            'corporate_tax_attachment' => 'Anlage zum Körperschaftsteuerbescheid',
            'section_60a_notice' => 'Bescheid nach § 60a Abs. 1 AO',
            default => 'Bescheid',
        };
        if (($data['tax_privilege_notice_type'] ?? null) !== 'section_60a_notice'
            && is_string($data['tax_privilege_assessment_period'] ?? null)
            && trim($data['tax_privilege_assessment_period']) !== '') {
            $notice .= ' (Veranlagungszeitraum '.trim($data['tax_privilege_assessment_period']).')';
        }
        $replacements['{{verein.tax_privilege_notice}}'] = $notice;
        $replacements['{{verein.tax_privilege_notice_date}}'] = ! empty($data['tax_privilege_notice_date'])
            ? CarbonImmutable::parse($data['tax_privilege_notice_date'])->format('d.m.Y')
            : '[Datum des Bescheids]';
        $replacements['{{verein.donation_purposes}}'] = collect(DonationPurposes::options())
            ->only($purposeCodes)->values()->implode(', ') ?: '[steuerbegünstigte Zwecke laut Bescheid]';
        $replacements['{{verein.deductible_scope}}'] = ($data['contributions_tax_deductible'] ?? false)
            ? 'Spenden und Mitgliedsbeiträge'
            : 'Spenden';

        return strtr($text, $replacements);
    }
}
