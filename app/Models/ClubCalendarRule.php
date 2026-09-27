<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $club_calendar_id
 * @property string $field_key
 * @property string $value
 */
class ClubCalendarRule extends Model
{
    protected $fillable = ['field_key', 'value'];

    /** @return BelongsTo<ClubCalendar, $this> */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(ClubCalendar::class, 'club_calendar_id');
    }
}
