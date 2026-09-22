<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property int $position
 * @property list<array{value: string, label: string, active: bool}> $options
 */
class MemberFieldDefinition extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['options' => 'array', 'required' => 'boolean', 'is_active' => 'boolean', 'is_custom' => 'boolean', 'filterable' => 'boolean', 'show_in_table' => 'boolean', 'position' => 'integer', 'max_length' => 'integer'];
    }
}
