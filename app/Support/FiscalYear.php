<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * A club's financial year. It may start in any month; reports and period
 * templates follow it, document numbers and donation receipts keep the
 * calendar year.
 */
final readonly class FiscalYear
{
    private function __construct(public CarbonImmutable $start, public CarbonImmutable $end) {}

    public static function containing(CarbonImmutable $date, int $startMonth = 1): self
    {
        $startMonth = max(1, min(12, $startMonth));
        $year = $date->month >= $startMonth ? $date->year : $date->year - 1;
        $start = CarbonImmutable::create($year, $startMonth, 1)->startOfDay();

        return new self($start, $start->addYear()->subDay());
    }

    /** "2026" for a calendar year, "2025/26" otherwise. */
    public function label(): string
    {
        return $this->start->year === $this->end->year
            ? (string) $this->start->year
            : $this->start->year.'/'.$this->end->format('y');
    }

    public function previous(): self
    {
        return self::containing($this->start->subDay(), $this->start->month);
    }

    /**
     * Whole year, halves and quarters as contribution period templates.
     *
     * @return list<array{value: string, label: string, start: string, end: string, description: string}>
     */
    public function periodTemplates(): array
    {
        $label = $this->label();
        $part = fn (string $value, string $name, int $fromMonth, int $months): array => [
            'value' => $value,
            'label' => $name,
            'start' => $this->start->addMonths($fromMonth)->toDateString(),
            'end' => $this->start->addMonths($fromMonth + $months)->subDay()->toDateString(),
            'description' => 'Mitgliedsbeitrag '.($value === 'year' ? '' : $name.' ').$label,
        ];

        return [
            $part('year', $this->start->month === 1 ? 'Jahr '.$label : 'Geschäftsjahr '.$label, 0, 12),
            $part('h1', '1. Halbjahr', 0, 6),
            $part('h2', '2. Halbjahr', 6, 6),
            $part('q1', '1. Quartal', 0, 3),
            $part('q2', '2. Quartal', 3, 3),
            $part('q3', '3. Quartal', 6, 3),
            $part('q4', '4. Quartal', 9, 3),
        ];
    }
}
