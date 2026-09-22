<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @property array<string, mixed> $data */
class ClubSetting extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['data' => 'array', 'version' => 'integer', 'fields_version' => 'integer'];
    }

    public static function current(): self
    {
        return static::query()->whereKey(1)->firstOrFail();
    }
}
