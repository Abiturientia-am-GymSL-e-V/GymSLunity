<?php

declare(strict_types=1);

namespace App\Members;

use App\Models\Member;
use App\Models\MemberFieldDefinition;

/**
 * Checks sharing rules of the form "field has value", as used for
 * calendars and bookable resources. Department, office and honor fields
 * match the options assigned today.
 */
final class MemberFieldRule
{
    /** @var list<string>|null */
    private ?array $temporalKeys = null;

    /** @var array<int, array<string, list<string>>> */
    private array $assignments = [];

    public function matches(Member $member, string $field, string $expected): bool
    {
        if ($field === '*' && $expected === '*') {
            return true;
        }
        $this->temporalKeys ??= array_values(MemberFieldDefinition::query()->whereIn('type', MemberFieldDefinition::TEMPORAL_TYPES)->pluck('key')->all());
        if (in_array($field, $this->temporalKeys, true)) {
            $this->assignments[$member->getKey()] ??= CurrentAssignments::values($member);

            return in_array($expected, $this->assignments[$member->getKey()][$field] ?? [], true);
        }

        $value = str_starts_with($field, 'custom_')
            ? ($member->custom_values[$field] ?? null)
            : $member->getAttribute($field);

        return (string) (is_bool($value) ? (int) $value : $value) === $expected;
    }

    /**
     * Options offered for such rules: select, boolean, department, office
     * and honor fields with at least one active option.
     *
     * @return list<array{key: string, label: string, options: list<array{value: string, label: string}>}>
     */
    public static function fields(): array
    {
        $result = [];
        foreach (MemberFieldDefinition::query()->where('is_active', true)->whereIn('type', ['select', 'boolean', ...MemberFieldDefinition::TEMPORAL_TYPES])->orderBy('position')->get() as $field) {
            $options = $field->type === 'boolean'
                ? [['value' => '1', 'label' => 'Ja'], ['value' => '0', 'label' => 'Nein']]
                : array_values(collect($field->options)->filter(fn (array $option): bool => $option['active'])->map(
                    fn (array $option): array => ['value' => $option['value'], 'label' => $option['label']],
                )->all());
            if ($options !== []) {
                $result[] = ['key' => $field->key, 'label' => $field->label, 'options' => $options];
            }
        }

        return $result;
    }
}
