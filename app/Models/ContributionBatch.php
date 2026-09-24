<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $actor_id
 * @property string $actor_name
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property CarbonImmutable $due_date
 * @property string $description
 * @property array<string, mixed> $criteria
 * @property int $created_count
 * @property int $skipped_count
 * @property CarbonImmutable $created_at
 */
class ContributionBatch extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['criteria' => 'array', 'period_start' => 'immutable_date:Y-m-d', 'period_end' => 'immutable_date:Y-m-d', 'due_date' => 'immutable_date:Y-m-d', 'created_at' => 'immutable_datetime'];
    }
}
