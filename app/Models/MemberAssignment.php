<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Time-bound assignment of a member to an option of a department, office or
 * honor field. Periods are whole days and inclusive on both ends: whoever
 * leaves an office on 31.12. still holds it on 31.12. A missing start means
 * "unknown", a missing end means "open". Whether an assignment is current is
 * always derived from the dates, never stored.
 *
 * @property int $id
 * @property int $member_id
 * @property string $field_key
 * @property string $option_value
 * @property CarbonImmutable|null $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property string|null $note
 * @property string $source
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property-read Member $member
 */
class MemberAssignment extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date:Y-m-d', 'ends_on' => 'immutable_date:Y-m-d'];
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * Assignments valid on the given day.
     *
     * @param  Builder<MemberAssignment>  $query
     */
    public function scopeActiveOn(Builder $query, CarbonImmutable|string $date): void
    {
        $this->scopeOverlapping($query, $date, $date);
    }

    /**
     * Assignments valid on at least one day between $from and $to (inclusive).
     * NULL bounds are open.
     *
     * @param  Builder<MemberAssignment>  $query
     */
    public function scopeOverlapping(Builder $query, CarbonImmutable|string|null $from, CarbonImmutable|string|null $to): void
    {
        // Dates are bound as Y-m-d strings, which compare correctly in SQLite and MariaDB.
        if ($to !== null) {
            $to = self::day($to);
            $query->where(fn (Builder $start) => $start->whereNull('starts_on')->orWhere('starts_on', '<=', $to));
        }
        if ($from !== null) {
            $from = self::day($from);
            $query->where(fn (Builder $end) => $end->whereNull('ends_on')->orWhere('ends_on', '>=', $from));
        }
    }

    public function isActiveOn(CarbonImmutable|string $date): bool
    {
        return self::periodsOverlap($this->startsOn(), $this->endsOn(), self::day($date), self::day($date));
    }

    public function startsOn(): ?string
    {
        return $this->starts_on?->toDateString();
    }

    public function endsOn(): ?string
    {
        return $this->ends_on?->toDateString();
    }

    /** Inclusive periods as Y-m-d strings; NULL is an open bound. */
    public static function periodsOverlap(?string $startA, ?string $endA, ?string $startB, ?string $endB): bool
    {
        return ($startA === null || $endB === null || $startA <= $endB)
            && ($startB === null || $endA === null || $startB <= $endA);
    }

    /** Overlapping, or one period ends the day before the other starts. */
    public static function periodsTouch(?string $startA, ?string $endA, ?string $startB, ?string $endB): bool
    {
        return self::periodsOverlap($startA, $endA, $startB, $endB)
            || ($endA !== null && $startB !== null && self::nextDay($endA) === $startB)
            || ($endB !== null && $startA !== null && self::nextDay($endB) === $startA);
    }

    public static function nextDay(string $date): string
    {
        return CarbonImmutable::parse($date)->addDay()->toDateString();
    }

    private static function day(CarbonImmutable|string $date): string
    {
        return $date instanceof CarbonImmutable ? $date->toDateString() : CarbonImmutable::parse($date)->toDateString();
    }
}
