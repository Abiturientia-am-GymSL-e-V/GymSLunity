<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $club_calendar_id
 * @property string $title
 * @property string|null $location
 * @property string|null $description
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property bool $all_day
 * @property ClubCalendar $calendar
 */
class ClubCalendarEvent extends Model
{
    protected $fillable = ['club_calendar_id', 'title', 'location', 'description', 'starts_at', 'ends_at', 'all_day', 'created_by'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'all_day' => 'boolean'];
    }

    /** @return BelongsTo<ClubCalendar, $this> */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(ClubCalendar::class, 'club_calendar_id');
    }
}
