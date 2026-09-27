<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property string $name
 * @property string $color
 * @property string $type
 * @property string|null $public_token
 * @property Collection<int, ClubCalendarRule> $rules
 */
class ClubCalendar extends Model
{
    protected $fillable = ['name', 'color', 'type', 'public_token'];

    /** @return HasMany<ClubCalendarEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ClubCalendarEvent::class);
    }

    /** @return HasMany<ClubCalendarRule, $this> */
    public function rules(): HasMany
    {
        return $this->hasMany(ClubCalendarRule::class);
    }
}
