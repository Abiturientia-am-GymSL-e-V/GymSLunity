<?php

declare(strict_types=1);

namespace App\Members;

use App\Models\Member;
use App\Models\MemberFieldDefinition;
use Illuminate\Support\Arr;

final class MemberFields
{
    public const SELFSERVICE_PROTECTED = [
        'email', 'deceased_at', 'joined_at', 'left_at', 'department_role', 'club_role',
        'iban', 'mandate_reference', 'mandate_signed_at', 'account_holder_first_name',
        'account_holder_last_name', 'account_holder_street', 'account_holder_postal_code',
        'account_holder_city', 'account_holder_country', 'mandate_type',
    ];

    public const ADDITIONAL_FIELDS = [
        'gender', 'sponsor_contribution', 'iban', 'mandate_reference', 'mandate_signed_at', 'mandate_type',
        'account_holder_first_name', 'account_holder_last_name', 'account_holder_street',
        'account_holder_postal_code', 'account_holder_city', 'account_holder_country', 'payment_method',
    ];

    public const SECTIONS = [
        'personal' => 'Persönliche Angaben', 'contact' => 'Kontakt & Adresse',
        'membership' => 'Mitgliedschaft', 'roles' => 'Funktionen',
        'bank' => 'Bankverbindung & SEPA', 'account' => 'Abweichender Kontoinhaber', 'additional' => 'Weitere Angaben',
    ];

    /** @return list<string> */
    public static function core(): array
    {
        return array_values(array_diff([...Member::LIST_FIELDS, ...self::ADDITIONAL_FIELDS], ['id', 'member_number', 'custom_values']));
    }

    /**
     * Keys writable through the member form. Department, office and honor
     * fields change only through MemberAssignments.
     *
     * @return list<string>
     */
    public static function writable(): array
    {
        return array_values(MemberFieldDefinition::query()->where('is_active', true)->whereNotIn('type', MemberFieldDefinition::TEMPORAL_TYPES)->orderBy('id')->pluck('key')->map(fn ($key): string => (string) $key)->all());
    }

    /** @return array<string, mixed> */
    public static function snapshot(Member $member): array
    {
        return [...Arr::only($member->attributesToArray(), self::core()), ...($member->custom_values ?? [])];
    }

    /** @return list<array{key: string, title: string, fields: list<array<string, mixed>>}> */
    public static function sections(?Member $member = null): array
    {
        $definitions = MemberFieldDefinition::query()->orderBy('position')->orderBy('id')->get();
        $values = $member ? self::snapshot($member) : [];
        $result = [];
        foreach (self::SECTIONS as $key => $title) {
            $fields = [];
            foreach ($definitions as $definition) {
                if ($definition->section === $key && $definition->is_active) {
                    $fields[] = self::descriptor($definition);
                }
            }
            if ($fields !== []) {
                $result[] = compact('key', 'title', 'fields');
            }
        }
        if ($member) {
            $archived = [];
            foreach ($definitions as $definition) {
                if (! $definition->is_active && array_key_exists($definition->key, $values) && $values[$definition->key] !== null && $values[$definition->key] !== '') {
                    $archived[] = [...self::descriptor($definition), 'readOnly' => true];
                }
            }
            if ($archived !== []) {
                $result[] = ['key' => 'archived', 'title' => 'Archivierte Angaben', 'fields' => $archived];
            }
        }

        return $result;
    }

    /**
     * Department, office and honor fields shown in the member record: all
     * active ones plus inactive ones that still hold assignments of the member.
     *
     * @return list<array<string, mixed>>
     */
    public static function assignmentFields(?Member $member = null): array
    {
        $used = $member ? $member->assignments()->distinct()->pluck('field_key')->all() : [];

        return array_values(MemberFieldDefinition::query()->whereIn('type', MemberFieldDefinition::TEMPORAL_TYPES)
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('key', $used))
            ->orderBy('position')->orderBy('id')->get()
            ->map(fn (MemberFieldDefinition $definition): array => [...self::descriptor($definition), 'readOnly' => ! $definition->is_active])
            ->all());
    }

    /**
     * Assignments of the member per field key, oldest first.
     *
     * @return array<string, list<array{id: int, option: string, starts_on: string|null, ends_on: string|null, note: string|null, source: string}>>
     */
    public static function assignments(Member $member): array
    {
        $grouped = [];
        foreach ($member->assignments()->orderByRaw('starts_on IS NULL DESC')->orderBy('starts_on')->orderBy('id')->get() as $assignment) {
            $grouped[$assignment->field_key][] = [
                'id' => $assignment->id, 'option' => $assignment->option_value, 'starts_on' => $assignment->startsOn(),
                'ends_on' => $assignment->endsOn(), 'note' => $assignment->note, 'source' => $assignment->source,
            ];
        }

        return $grouped;
    }

    /** @return array<string, mixed> */
    public static function descriptor(MemberFieldDefinition $definition): array
    {
        $options = [];
        $activeOptions = [];
        foreach ($definition->options as $option) {
            $options[$option['value']] = $option['label'];
            if ($option['active']) {
                $activeOptions[$option['value']] = $option['label'];
            }
        }

        return [
            'key' => $definition->key, 'label' => $definition->label, 'type' => $definition->type,
            'required' => $definition->required, 'max' => $definition->max_length, 'options' => $options,
            'activeOptions' => $activeOptions, 'readOnly' => false,
            'emptyLabel' => in_array($definition->key, ['department_role', 'club_role'], true) ? 'Keine' : 'Nicht hinterlegt',
            'custom' => $definition->is_custom, 'filterable' => $definition->filterable, 'showInTable' => $definition->show_in_table,
            'selfserviceVisible' => $definition->selfservice_visible,
            'selfserviceEditable' => $definition->selfservice_editable,
            ...($definition->isTemporal() ? [
                'allowMultiple' => $definition->allow_multiple,
                'optionDetails' => array_map(fn (array $option): array => [
                    'value' => $option['value'], 'label' => $option['label'], 'active' => $option['active'],
                    'board' => (bool) ($option['board'] ?? false), 'mandatory' => (bool) ($option['mandatory'] ?? false),
                    'maxHolders' => $option['max_holders'] ?? null, 'repeatable' => (bool) ($option['repeatable'] ?? false),
                ], $definition->options),
            ] : []),
        ];
    }

    /** @return list<array{key: string, title: string, fields: list<array<string, mixed>>}> */
    public static function selfserviceSections(?Member $member = null): array
    {
        return array_values(collect(self::sections($member))
            ->map(fn (array $section): array => [
                ...$section,
                'fields' => array_values(array_map(
                    fn (array $field): array => [
                        ...$field,
                        'readOnly' => ! $field['selfserviceEditable'] || in_array($field['key'], self::SELFSERVICE_PROTECTED, true),
                    ],
                    array_filter($section['fields'], fn (array $field): bool => $field['selfserviceVisible']),
                )),
            ])
            ->filter(fn (array $section): bool => $section['fields'] !== [])
            ->values()
            ->all());
    }

    /** @return list<array<string, mixed>> */
    public static function directoryFields(): array
    {
        return array_merge(...array_map(
            fn (array $section): array => $section['fields'],
            self::sections(),
        ));
    }
}
