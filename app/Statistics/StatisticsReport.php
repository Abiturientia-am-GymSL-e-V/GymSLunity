<?php

namespace App\Statistics;

use App\Models\Contribution;
use App\Models\Donation;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

final class StatisticsReport
{
    /** @var array<int, string> */
    private const MONTH_LABELS = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mär', 4 => 'Apr', 5 => 'Mai', 6 => 'Jun',
        7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Dez',
    ];

    /** @var EloquentCollection<int, Member> */
    private EloquentCollection $members;

    /** @var EloquentCollection<int, Member> */
    private EloquentCollection $activeMembers;

    public function __construct(
        private readonly CarbonImmutable $from,
        private readonly CarbonImmutable $to,
        private readonly CarbonImmutable $asOf,
    ) {
        $this->members = Member::query()->get([
            'id', 'joined_at', 'left_at', 'deceased_at', 'membership_type', 'birth_date',
            'gender', 'payment_method', 'city', 'email', 'street', 'postal_code', 'country',
            'iban', 'mandate_reference', 'mandate_signed_at',
        ]);
        $this->activeMembers = $this->members
            ->filter(fn (Member $member): bool => $this->isActiveAt($member, $this->asOf))
            ->values();
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $memberTrend = $this->memberTrend();
        $contributions = Contribution::query()
            ->whereBetween('due_date', [$this->from->toDateString(), $this->to->toDateString()])
            ->orderBy('due_date')
            ->get();
        $donations = Donation::query()
            ->whereBetween('donated_at', [$this->from->toDateString(), $this->to->toDateString()])
            ->orderBy('donated_at')
            ->get();

        return [
            'summary' => $this->summary(),
            'memberTrend' => $memberTrend,
            'memberBreakdowns' => $this->memberBreakdowns(),
            'stockReport' => $this->stockReport(),
            'finances' => $this->finances($contributions, $donations, $memberTrend),
            'dataQuality' => $this->dataQuality(),
        ];
    }

    /** @return array<string, int> */
    private function summary(): array
    {
        $joined = $this->members->filter(fn (Member $member): bool => $this->dateInPeriod($member->joined_at))->count();
        $departed = $this->members->filter(fn (Member $member): bool => $this->dateInPeriod($this->departureDate($member)))->count();

        return [
            'active_members' => $this->activeMembers->count(),
            'contacts' => $this->members->filter(fn (Member $member): bool => $member->joined_at === null && $this->departureDate($member) === null)->count(),
            'joined' => $joined,
            'departed' => $departed,
            'net_change' => $joined - $departed,
        ];
    }

    /** @return list<array{key: string, label: string, active: int, joined: int, departed: int}> */
    private function memberTrend(): array
    {
        $rows = [];
        $month = $this->from->startOfMonth();
        while ($month->lessThanOrEqualTo($this->to)) {
            $periodStart = $month->lessThan($this->from) ? $this->from : $month;
            $monthEnd = $month->endOfMonth();
            $periodEnd = $monthEnd->greaterThan($this->to) ? $this->to : $monthEnd;
            $rows[] = [
                'key' => $month->format('Y-m'),
                'label' => self::MONTH_LABELS[$month->month].' '.$month->format('y'),
                'active' => $this->members->filter(fn (Member $member): bool => $this->isActiveAt($member, $periodEnd))->count(),
                'joined' => $this->members->filter(fn (Member $member): bool => $this->dateBetween($member->joined_at, $periodStart, $periodEnd))->count(),
                'departed' => $this->members->filter(fn (Member $member): bool => $this->dateBetween($this->departureDate($member), $periodStart, $periodEnd))->count(),
            ];
            $month = $month->addMonth();
        }

        return $rows;
    }

    /** @return array<string, list<array{label: string, count: int}>> */
    private function memberBreakdowns(): array
    {
        return [
            'membership_types' => $this->breakdown(fn (Member $member): string => $this->label($member->membership_type)),
            'age_groups' => $this->breakdown(fn (Member $member): string => $this->ageGroup($member)),
            'genders' => $this->breakdown(fn (Member $member): string => $this->genderLabel($member->gender)),
            'payment_methods' => $this->breakdown(fn (Member $member): string => $this->label($member->payment_method)),
            'cities' => $this->topCities(),
        ];
    }

    /** @return list<array{birth_year: int|null, label: string, female: int, male: int, diverse: int, unspecified: int, total: int}> */
    public function stockReport(): array
    {
        return array_values($this->activeMembers
            ->groupBy(fn (Member $member): string => $member->birth_date?->format('Y') ?? 'unknown')
            ->map(function (EloquentCollection $members, string $year): array {
                return [
                    'birth_year' => $year === 'unknown' ? null : (int) $year,
                    'label' => $year === 'unknown' ? 'Ohne Geburtsdatum' : $year,
                    'female' => $members->where('gender', 'w')->count(),
                    'male' => $members->where('gender', 'm')->count(),
                    'diverse' => $members->where('gender', 'd')->count(),
                    'unspecified' => $members->filter(fn (Member $member): bool => ! in_array($member->gender, ['w', 'm', 'd'], true))->count(),
                    'total' => $members->count(),
                ];
            })
            ->sortBy(fn (array $row): int => $row['birth_year'] ?? PHP_INT_MAX)
            ->values()
            ->all());
    }

    /**
     * @param  EloquentCollection<int, Contribution>  $contributions
     * @param  EloquentCollection<int, Donation>  $donations
     * @param  list<array{key: string, label: string, active: int, joined: int, departed: int}>  $memberTrend
     * @return array<string, mixed>
     */
    private function finances(EloquentCollection $contributions, EloquentCollection $donations, array $memberTrend): array
    {
        $assessed = (int) $contributions->sum('amount_cents');
        $paid = (int) $contributions->sum('paid_cents');
        $open = $contributions->sum(fn (Contribution $contribution): int => $contribution->remainingCents());
        $today = CarbonImmutable::today();
        $overdue = $contributions->filter(fn (Contribution $contribution): bool => $contribution->remainingCents() > 0 && $contribution->due_date->lessThan($today));
        $donationTotal = (int) $donations->sum('amount_cents');

        $months = collect($memberTrend)->map(function (array $month) use ($contributions, $donations): array {
            $start = CarbonImmutable::createFromFormat('!Y-m', $month['key'])->startOfMonth();
            $end = $start->endOfMonth();

            return [
                'key' => $month['key'],
                'label' => $month['label'],
                'contributions_cents' => (int) $contributions->filter(fn (Contribution $contribution): bool => $this->dateBetween($contribution->due_date, $start, $end))->sum('amount_cents'),
                'donations_cents' => (int) $donations->filter(fn (Donation $donation): bool => $this->dateBetween($donation->donated_at, $start, $end))->sum('amount_cents'),
            ];
        })->all();

        $donationLabels = [
            'money' => 'Geldzuwendungen',
            'material' => 'Sachzuwendungen',
            'membership_fee' => 'Mitgliedsbeiträge',
            'expense_waiver' => 'Aufwandsverzicht',
        ];

        return [
            'contributions' => [
                'count' => $contributions->count(),
                'assessed_cents' => $assessed,
                'paid_cents' => $paid,
                'open_cents' => $open,
                'overdue_count' => $overdue->count(),
                'overdue_cents' => (int) $overdue->sum(fn (Contribution $contribution): int => $contribution->remainingCents()),
                'collection_rate' => $assessed > 0 ? (int) round(($paid / $assessed) * 100) : 0,
            ],
            'donations' => [
                'count' => $donations->count(),
                'amount_cents' => $donationTotal,
                'average_cents' => $donations->isNotEmpty() ? (int) round($donationTotal / $donations->count()) : 0,
                'by_type' => $donations->groupBy('donation_type')->map(fn (EloquentCollection $items, string $type): array => [
                    'label' => $donationLabels[$type] ?? $type,
                    'count' => $items->count(),
                    'amount_cents' => (int) $items->sum('amount_cents'),
                ])->sortByDesc('amount_cents')->values()->all(),
            ],
            'monthly' => $months,
        ];
    }

    /** @return array{score: int, checks: list<array{key: string, label: string, description: string, count: int, percentage: int}>} */
    private function dataQuality(): array
    {
        $checks = [
            ['email', 'E-Mail-Adresse fehlt', 'Erschwert Selfservice und digitale Kommunikation.', fn (Member $member): bool => $this->blank($member->email)],
            ['birth_date', 'Geburtsdatum fehlt', 'Verhindert Alters- und Verbandsauswertungen.', fn (Member $member): bool => $member->birth_date === null],
            ['gender', 'Geschlecht fehlt', 'Macht Bestandsmeldungen unvollständig.', fn (Member $member): bool => $this->blank($member->gender)],
            ['address', 'Anschrift unvollständig', 'Straße, PLZ, Ort oder Land sind nicht vollständig.', fn (Member $member): bool => $this->blank($member->street) || $this->blank($member->postal_code) || $this->blank($member->city) || $this->blank($member->country)],
            ['payment_method', 'Zahlungsart fehlt', 'Beitragsläufe können nicht zuverlässig vorbereitet werden.', fn (Member $member): bool => $this->blank($member->payment_method)],
            ['sepa', 'SEPA-Mandat unvollständig', 'Bei Lastschrift fehlen IBAN, Referenz oder Unterschriftsdatum.', fn (Member $member): bool => $member->payment_method === 'SEPA-Lastschrift' && ($this->blank($member->iban) || $this->blank($member->mandate_reference) || $member->mandate_signed_at === null)],
        ];
        $baseChecks = 5;
        $missingBase = 0;
        $rows = [];
        foreach ($checks as [$key, $label, $description, $check]) {
            $count = $this->activeMembers->filter($check)->count();
            if ($key !== 'sepa') {
                $missingBase += $count;
            }
            $rows[] = [
                'key' => $key,
                'label' => $label,
                'description' => $description,
                'count' => $count,
                'percentage' => $this->activeMembers->isNotEmpty() ? (int) round(($count / $this->activeMembers->count()) * 100) : 0,
            ];
        }
        $possible = $this->activeMembers->count() * $baseChecks;

        return [
            'score' => $possible > 0 ? (int) round((($possible - $missingBase) / $possible) * 100) : 100,
            'checks' => $rows,
        ];
    }

    /**
     * @param  callable(Member): string  $label
     * @return list<array{label: string, count: int}>
     */
    private function breakdown(callable $label): array
    {
        return array_values($this->activeMembers->countBy($label)->map(fn (int $count, string $name): array => [
            'label' => $name,
            'count' => $count,
        ])->sortByDesc('count')->values()->all());
    }

    /** @return list<array{label: string, count: int}> */
    private function topCities(): array
    {
        $groups = $this->activeMembers->countBy(fn (Member $member): string => $this->label($member->city))->sortDesc();
        $top = $groups->take(8)->map(fn (int $count, string $label): array => compact('label', 'count'))->values();
        $other = $groups->skip(8)->sum();
        if ($other > 0) {
            $top->push(['label' => 'Weitere Orte', 'count' => $other]);
        }

        return array_values($top->all());
    }

    private function ageGroup(Member $member): string
    {
        if ($member->birth_date === null) {
            return 'Ohne Geburtsdatum';
        }
        $age = (int) $member->birth_date->diffInYears($this->asOf);

        return match (true) {
            $age < 18 => 'Unter 18',
            $age <= 25 => '18–25',
            $age <= 40 => '26–40',
            $age <= 60 => '41–60',
            default => '61 und älter',
        };
    }

    private function genderLabel(?string $gender): string
    {
        return match ($gender) {
            'w' => 'Weiblich',
            'm' => 'Männlich',
            'd' => 'Divers',
            'o' => 'Ohne Angabe',
            default => 'Nicht hinterlegt',
        };
    }

    private function label(?string $value): string
    {
        return $this->blank($value) ? 'Nicht hinterlegt' : trim((string) $value);
    }

    private function isActiveAt(Member $member, CarbonImmutable $date): bool
    {
        return $member->joined_at !== null
            && $member->joined_at->lessThanOrEqualTo($date)
            && ($member->left_at === null || $member->left_at->greaterThan($date))
            && ($member->deceased_at === null || $member->deceased_at->greaterThan($date));
    }

    private function departureDate(Member $member): ?CarbonImmutable
    {
        if ($member->left_at === null) {
            return $member->deceased_at;
        }
        if ($member->deceased_at === null) {
            return $member->left_at;
        }

        return $member->left_at->min($member->deceased_at);
    }

    private function dateInPeriod(?CarbonImmutable $date): bool
    {
        return $this->dateBetween($date, $this->from, $this->to);
    }

    private function dateBetween(?CarbonImmutable $date, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        return $date !== null && $date->betweenIncluded($from, $to);
    }

    private function blank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }
}
