<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\FiscalYear;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class FiscalYearTest extends TestCase
{
    public function test_a_calendar_year_is_the_default(): void
    {
        $year = FiscalYear::containing(CarbonImmutable::parse('2026-09-23'));

        $this->assertSame(['2026-01-01', '2026-12-31', '2026'], [$year->start->toDateString(), $year->end->toDateString(), $year->label()]);
    }

    public function test_a_financial_year_starting_in_july_spans_two_calendar_years(): void
    {
        $before = FiscalYear::containing(CarbonImmutable::parse('2026-06-30'), 7);
        $after = FiscalYear::containing(CarbonImmutable::parse('2026-07-01'), 7);

        $this->assertSame(['2025-07-01', '2026-06-30', '2025/26'], [$before->start->toDateString(), $before->end->toDateString(), $before->label()]);
        $this->assertSame(['2026-07-01', '2027-06-30', '2026/27'], [$after->start->toDateString(), $after->end->toDateString(), $after->label()]);
        $this->assertSame('2025/26', $after->previous()->label());
    }

    public function test_period_templates_follow_the_financial_year(): void
    {
        $templates = collect(FiscalYear::containing(CarbonImmutable::parse('2026-09-23'), 7)->periodTemplates())->keyBy('value');

        $this->assertSame('Geschäftsjahr 2026/27', $templates['year']['label']);
        $this->assertSame('Mitgliedsbeitrag 2026/27', $templates['year']['description']);
        $this->assertSame(['2026-07-01', '2026-12-31'], [$templates['h1']['start'], $templates['h1']['end']]);
        $this->assertSame(['2027-04-01', '2027-06-30'], [$templates['q4']['start'], $templates['q4']['end']]);
        $this->assertSame('Mitgliedsbeitrag 2. Quartal 2026/27', $templates['q2']['description']);
    }

    public function test_february_ends_correctly_in_a_financial_year_starting_in_march(): void
    {
        $year = FiscalYear::containing(CarbonImmutable::parse('2028-01-15'), 3);

        $this->assertSame(['2027-03-01', '2028-02-29'], [$year->start->toDateString(), $year->end->toDateString()]);
    }
}
