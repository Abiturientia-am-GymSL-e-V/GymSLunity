<?php

namespace App\Members;

use App\Models\Member;
use App\Models\MemberFieldDefinition;
use Illuminate\Support\Arr;

final class MemberFields
{
    public const ADDITIONAL_FIELDS = [
        'gender', 'sponsor_contribution', 'iban', 'mandate_reference', 'mandate_signed_at',
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

    /** @return list<string> */
    public static function writable(): array
    {
        return array_values(MemberFieldDefinition::query()->where('is_active', true)->orderBy('id')->pluck('key')->map(fn ($key): string => (string) $key)->all());
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
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function directoryFields(): array
    {
        return array_values(MemberFieldDefinition::query()->where('is_active', true)->orderBy('position')->orderBy('id')->get()
            ->map(fn (MemberFieldDefinition $field): array => self::descriptor($field))->all());
    }
}
