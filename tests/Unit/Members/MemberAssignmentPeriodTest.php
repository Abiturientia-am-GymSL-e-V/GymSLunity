<?php

declare(strict_types=1);

namespace Tests\Unit\Members;

use App\Models\MemberAssignment;
use PHPUnit\Framework\TestCase;

class MemberAssignmentPeriodTest extends TestCase
{
    public function test_periods_are_inclusive_on_both_ends(): void
    {
        $this->assertTrue(MemberAssignment::periodsOverlap('2020-01-01', '2020-12-31', '2020-12-31', '2021-12-31'));
        $this->assertTrue(MemberAssignment::periodsOverlap('2020-01-01', '2020-12-31', '2019-01-01', '2020-01-01'));
        $this->assertFalse(MemberAssignment::periodsOverlap('2020-01-01', '2020-12-31', '2021-01-01', null));
        $this->assertFalse(MemberAssignment::periodsOverlap('2020-01-01', '2020-12-31', null, '2019-12-31'));
    }

    public function test_missing_bounds_are_open(): void
    {
        $this->assertTrue(MemberAssignment::periodsOverlap(null, null, '1900-01-01', '1900-01-01'));
        $this->assertTrue(MemberAssignment::periodsOverlap(null, '2020-01-01', '2020-01-01', '2020-01-01'));
        $this->assertTrue(MemberAssignment::periodsOverlap('2020-01-01', null, '2099-01-01', '2099-01-01'));
        $this->assertFalse(MemberAssignment::periodsOverlap(null, '2019-12-31', '2020-01-01', null));
    }

    public function test_adjacent_periods_touch_but_do_not_overlap(): void
    {
        $this->assertFalse(MemberAssignment::periodsOverlap('2020-01-01', '2020-12-31', '2021-01-01', null));
        $this->assertTrue(MemberAssignment::periodsTouch('2020-01-01', '2020-12-31', '2021-01-01', null));
        $this->assertTrue(MemberAssignment::periodsTouch('2021-01-01', null, '2020-01-01', '2020-12-31'));
        $this->assertFalse(MemberAssignment::periodsTouch('2020-01-01', '2020-12-30', '2021-01-01', null));
        $this->assertSame('2024-03-01', MemberAssignment::nextDay('2024-02-29'));
    }
}
