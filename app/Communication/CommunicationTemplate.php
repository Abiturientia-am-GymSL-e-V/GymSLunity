<?php

declare(strict_types=1);

namespace App\Communication;

use App\Configuration\ClubData;
use App\Configuration\ClubSettings;
use App\Members\MemberFields;
use App\Members\MemberReportValue;
use App\Models\Member;
use App\Models\MemberFieldDefinition;
use App\Support\Iban;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class CommunicationTemplate
{
    private const SAFE_MEMBER_FIELDS = [
        'first_name', 'middle_name', 'last_name', 'email', 'mobile_phone',
        'street', 'postal_code', 'city', 'country', 'birth_date',
        'membership_type', 'department_role', 'club_role', 'is_honorary',
        'joined_at', 'left_at',
    ];

    /** @var Collection<string, MemberFieldDefinition> */
    private Collection $fields;

    /** @var array<string, string> */
    private array $clubReplacements;

    public function __construct(private readonly ClubSettings $clubSettings)
    {
        $this->fields = MemberFieldDefinition::query()
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->where('is_custom', true)->orWhereIn('key', self::SAFE_MEMBER_FIELDS);
            })
            ->orderBy('position')->get()->keyBy('key');
        $club = $this->clubSettings->data();
        $this->clubReplacements = [];
        foreach (ClubData::fields() as $field) {
            $value = $club[$field['key']] ?? '';
            if ($field['type'] === 'boolean') {
                $value = $value ? 'Ja' : 'Nein';
            } elseif ($field['type'] === 'date' && is_string($value) && $value !== '') {
                $value = CarbonImmutable::parse($value)->format('d.m.Y');
            } elseif ($field['key'] === 'iban') {
                $value = Iban::format(is_string($value) ? $value : null);
            }
            $this->clubReplacements['{{verein.'.$field['key'].'}}'] = (string) $value;
        }
    }

    /** @return list<array{token: string, label: string, group: string}> */
    public function placeholders(): array
    {
        $items = [
            ['token' => '{{mitglied.anrede}}', 'label' => 'Anrede', 'group' => 'Mitglied'],
            ['token' => '{{mitglied.briefanrede}}', 'label' => 'Briefanrede', 'group' => 'Mitglied'],
            ['token' => '{{mitglied.name}}', 'label' => 'Vollständiger Name', 'group' => 'Mitglied'],
            ['token' => '{{mitglied.mitgliedsnummer}}', 'label' => 'Mitgliedsnummer', 'group' => 'Mitglied'],
            ['token' => '{{mitglied.adresse}}', 'label' => 'Mehrzeilige Anschrift', 'group' => 'Mitglied'],
            ['token' => '{{datum.heute}}', 'label' => 'Heutiges Datum', 'group' => 'Allgemein'],
        ];
        foreach ($this->fields as $field) {
            $items[] = ['token' => '{{mitglied.'.$field->key.'}}', 'label' => $field->label, 'group' => 'Mitglied'];
        }
        foreach (ClubData::fields() as $field) {
            $items[] = ['token' => '{{verein.'.$field['key'].'}}', 'label' => $field['label'], 'group' => 'Verein'];
        }

        return $items;
    }

    public function validate(string $template, string $field): void
    {
        preg_match_all('/\{\{.*?\}\}/s', $template, $matches);
        $allowed = array_column($this->placeholders(), 'token');
        $unknown = array_values(array_unique(array_diff($matches[0], $allowed)));
        $withoutKnown = str_replace($matches[0], '', $template);
        if ($unknown !== [] || str_contains($withoutKnown, '{{') || str_contains($withoutKnown, '}}')) {
            throw ValidationException::withMessages([
                $field => 'Unbekannter oder unvollständiger Platzhalter: '.($unknown[0] ?? 'Bitte die Klammern prüfen.'),
            ]);
        }
    }

    public function render(string $template, Member $member): string
    {
        return strtr($template, $this->replacements($member));
    }

    public function renderHtml(string $template, Member $member): string
    {
        return strtr($template, array_map(
            fn (string $value): string => nl2br(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8')),
            $this->replacements($member),
        ));
    }

    /** @return array<string, string> */
    private function replacements(Member $member): array
    {
        // Department, office and honor fields name the options valid today.
        $snapshot = MemberFields::reportSnapshot($member);
        $name = collect([$member->first_name, $member->middle_name, $member->last_name])->filter()->join(' ');
        $salutation = match ($member->gender) {
            'w' => 'Frau',
            'm' => 'Herr',
            default => '',
        };
        $letterSalutation = match ($member->gender) {
            'w' => 'Sehr geehrte Frau '.$member->last_name,
            'm' => 'Sehr geehrter Herr '.$member->last_name,
            default => 'Guten Tag '.$name,
        };
        $address = collect([
            $name,
            $member->street,
            trim(collect([$member->postal_code, $member->city])->filter()->join(' ')),
            $member->country,
        ])->filter(fn (?string $line): bool => is_string($line) && trim($line) !== '')->join("\n");
        $replacements = [
            ...$this->clubReplacements,
            '{{datum.heute}}' => now()->setTimezone(config('app.display_timezone'))->format('d.m.Y'),
            '{{mitglied.anrede}}' => $salutation,
            '{{mitglied.briefanrede}}' => $letterSalutation,
            '{{mitglied.name}}' => $name,
            '{{mitglied.mitgliedsnummer}}' => (string) $member->member_number,
            '{{mitglied.adresse}}' => $address,
        ];
        foreach ($this->fields as $field) {
            $descriptor = [
                'key' => $field->key,
                'type' => $field->type,
                'options' => collect($field->options)->pluck('label', 'value')->all(),
            ];
            $replacements['{{mitglied.'.$field->key.'}}'] = MemberReportValue::format($snapshot[$field->key] ?? null, $descriptor);
        }

        return $replacements;
    }
}
