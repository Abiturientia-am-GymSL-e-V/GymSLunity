<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property array<string, string|int|float|bool|null> $before
 * @property array<string, string|int|float|bool|null> $after
 * @property list<string> $changed_fields
 * @property array<string, array<string, mixed>>|null $field_schema
 * @property CarbonImmutable $created_at
 */
class MemberChange extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'changed_fields' => 'array', 'field_schema' => 'array', 'version' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Historieneinträge dürfen nicht verändert werden.'));
        static::deleting(fn () => throw new LogicException('Historieneinträge dürfen nicht gelöscht werden.'));
    }
}
