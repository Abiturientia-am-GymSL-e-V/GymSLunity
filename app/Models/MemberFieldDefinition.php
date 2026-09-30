<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property string $label
 * @property string $type
 * @property int $position
 * @property bool $selfservice_editable
 * @property bool $selfservice_visible
 * @property bool $allow_multiple
 * @property list<array{value: string, label: string, active: bool, board?: bool, mandatory?: bool, max_holders?: int|null, repeatable?: bool, jubilee_years?: int|null}> $options
 */
class MemberFieldDefinition extends Model
{
    /** Field types whose values are time-bound assignments instead of member attributes. */
    public const TEMPORAL_TYPES = ['department', 'office', 'honor'];

    /** Pseudo section of temporal fields; they never appear in the regular member form. */
    public const ASSIGNMENT_SECTION = 'assignments';

    protected $guarded = ['id'];

    public function isTemporal(): bool
    {
        return in_array($this->type, self::TEMPORAL_TYPES, true);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['options' => 'array', 'required' => 'boolean', 'is_active' => 'boolean', 'is_custom' => 'boolean', 'filterable' => 'boolean', 'show_in_table' => 'boolean', 'selfservice_visible' => 'boolean', 'selfservice_editable' => 'boolean', 'allow_multiple' => 'boolean', 'position' => 'integer', 'max_length' => 'integer'];
    }
}
