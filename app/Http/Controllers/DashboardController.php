<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Configuration\ClubSettings;
use App\Configuration\SoftwareModules;
use App\Models\ClubCalendarEvent;
use App\Models\Contribution;
use App\Models\Donation;
use App\Models\Member;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private readonly ClubSettings $clubSettings) {}

    public function __invoke(Request $request): Response
    {
        $today = Clock::today();
        $modules = SoftwareModules::values($this->clubSettings);
        $canViewMembers = $request->user()?->can('viewAny', Member::class) ?? false;
        $canViewPayments = $modules['payments'] && ($request->user()?->can('view-payments') ?? false);
        $canViewDonations = $modules['donations'] && ($request->user()?->can('view-donations') ?? false);
        $canViewCalendar = $modules['calendar'] && ($request->user()?->can('view-calendar') ?? false);

        return Inertia::render('Dashboard', [
            'today' => $today->format('Y-m-d'),
            'memberOverview' => $canViewMembers ? $this->memberOverview($today) : null,
            'birthdays' => $canViewMembers ? $this->birthdays($today) : null,
            'contributionOverview' => $canViewPayments ? $this->contributionOverview($today) : null,
            'donationOverview' => $canViewDonations ? $this->donationOverview($today) : null,
            'upcomingEvents' => $canViewCalendar ? $this->upcomingEvents($today) : null,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function upcomingEvents(CarbonImmutable $today): array
    {
        return array_values(ClubCalendarEvent::query()->with('calendar')
            ->where('ends_at', '>=', $today->startOfDay())
            ->orderBy('starts_at')->limit(10)->get()
            ->map(fn (ClubCalendarEvent $event): array => [
                'id' => $event->id, 'title' => $event->title, 'location' => $event->location,
                'starts_at' => $event->starts_at->format('Y-m-d\TH:i:s'), 'ends_at' => $event->ends_at->format('Y-m-d\TH:i:s'),
                'all_day' => $event->all_day, 'calendar_name' => $event->calendar->name, 'color' => $event->calendar->color,
            ])->all());
    }

    /** @return array<string, mixed> */
    private function memberOverview(CarbonImmutable $today): array
    {
        $current = $this->currentMembers($today);
        $fiscalYear = $this->clubSettings->fiscalYear($today);
        $yearStart = $fiscalYear->start->toDateString();
        $yearToDate = $today->toDateString();

        return [
            'year' => $fiscalYear->label(),
            'current_count' => (clone $current)->count(),
            'joined_this_year' => Member::query()->whereBetween('joined_at', [$yearStart, $yearToDate])->count(),
            'left_this_year' => Member::query()->whereBetween('left_at', [$yearStart, $yearToDate])->count(),
            'by_membership_type' => (clone $current)
                ->selectRaw('membership_type, COUNT(*) AS total')
                ->groupBy('membership_type')
                ->orderByDesc('total')
                ->orderBy('membership_type')
                ->get()
                ->map(fn (Member $member): array => [
                    'label' => $member->membership_type ?: 'Ohne Zuordnung',
                    'count' => (int) $member->getAttribute('total'),
                ])->values()->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function birthdays(CarbonImmutable $today): array
    {
        return array_values($this->currentMembers($today)
            ->whereNotNull('birth_date')
            ->get(['member_number', 'first_name', 'middle_name', 'last_name', 'birth_date'])
            ->map(fn (Member $member): ?array => $this->birthdayRow($member, $today))
            ->filter()
            ->sortBy(fn (array $birthday): int => $birthday['days_from_today'])
            ->values()
            ->all());
    }

    /** @return array<string, mixed>|null */
    private function birthdayRow(Member $member, CarbonImmutable $today): ?array
    {
        $birthDate = $member->birth_date;
        if (! $birthDate) {
            return null;
        }

        $occurrences = collect([$today->year - 1, $today->year, $today->year + 1])
            ->map(function (int $year) use ($birthDate): CarbonImmutable {
                $monthStart = CarbonImmutable::create($year, $birthDate->month, 1, 0, 0, 0, config('app.timezone'));

                return $monthStart->day(min($birthDate->day, $monthStart->daysInMonth));
            });
        $occurrence = $occurrences->sortBy(fn (CarbonImmutable $date): int => abs((int) $today->diffInDays($date, false)))->first();
        $daysFromToday = (int) $today->diffInDays($occurrence, false);
        if ($daysFromToday < -14 || $daysFromToday > 14) {
            return null;
        }

        return [
            'member_number' => $member->member_number,
            'name' => collect([$member->first_name, $member->middle_name, $member->last_name])->filter()->join(' '),
            'date' => $occurrence->format('Y-m-d'),
            'days_from_today' => $daysFromToday,
            'age' => $occurrence->year - $birthDate->year,
        ];
    }

    /** @return array<string, int|string> year label and figures in cents */
    private function contributionOverview(CarbonImmutable $today): array
    {
        $fiscalYear = $this->clubSettings->fiscalYear($today);
        $currentYear = Contribution::query()
            ->where('kind', 'contribution')
            ->whereBetween('due_date', [$fiscalYear->start->toDateString(), $fiscalYear->end->toDateString()]);
        $open = Contribution::query()->where('kind', 'contribution')->where('status', 'open');
        $overdue = (clone $open)->whereDate('due_date', '<', $today->toDateString());
        $assessedCents = (int) (clone $currentYear)->sum('amount_cents');
        $paidCents = (int) (clone $currentYear)->sum('paid_cents');

        return [
            'year' => $fiscalYear->label(),
            'assessed_count' => (clone $currentYear)->count(),
            'assessed_cents' => $assessedCents,
            'paid_cents' => $paidCents,
            'collection_rate' => $assessedCents > 0 ? (int) round(($paidCents / $assessedCents) * 100) : 0,
            'open_count' => (clone $open)->count(),
            'open_cents' => $this->remainingCents($open),
            'overdue_count' => (clone $overdue)->count(),
            'overdue_cents' => $this->remainingCents($overdue),
        ];
    }

    /** @return array<string, mixed> */
    private function donationOverview(CarbonImmutable $today): array
    {
        $currentYear = Donation::query()->whereBetween('donated_at', [
            $today->startOfYear()->toDateString(),
            $today->endOfYear()->toDateString(),
        ]);
        $count = (clone $currentYear)->count();
        $amountCents = (int) (clone $currentYear)->sum('amount_cents');
        $latest = (clone $currentYear)->latest('donated_at')->latest('id')->first();

        return [
            'year' => $today->year,
            'count' => $count,
            'amount_cents' => $amountCents,
            'average_cents' => $count > 0 ? (int) round($amountCents / $count) : 0,
            'open_certificate_count' => (clone $currentYear)->whereDoesntHave('certificate')->count(),
            'latest' => $latest ? [
                'donor_name' => $latest->donor_name,
                'amount_cents' => $latest->amount_cents,
                'donated_at' => $latest->donated_at->format('Y-m-d'),
            ] : null,
        ];
    }

    /** @return Builder<Member> */
    private function currentMembers(CarbonImmutable $today): Builder
    {
        $date = $today->toDateString();

        return Member::query()
            ->where(fn (Builder $query) => $query->whereNull('joined_at')->orWhereDate('joined_at', '<=', $date))
            ->where(fn (Builder $query) => $query->whereNull('left_at')->orWhereDate('left_at', '>', $date))
            ->where(fn (Builder $query) => $query->whereNull('deceased_at')->orWhereDate('deceased_at', '>', $date));
    }

    /** @param Builder<Contribution> $query */
    private function remainingCents(Builder $query): int
    {
        return (int) (clone $query)
            ->selectRaw('COALESCE(SUM(amount_cents - paid_cents), 0) AS total')
            ->value('total');
    }
}
