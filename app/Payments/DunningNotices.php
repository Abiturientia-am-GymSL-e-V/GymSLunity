<?php

declare(strict_types=1);

namespace App\Payments;

use App\Configuration\ClubSettings;
use App\Members\MemberReportWriter;
use App\Models\Contribution;
use App\Models\Member;
use App\Support\Clock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final class DunningNotices
{
    public function __construct(private readonly ClubSettings $clubSettings) {}

    /**
     * @param  list<int>|null  $memberNumbers
     * @return Collection<int, Member>
     */
    public function members(?array $memberNumbers = null): Collection
    {
        $members = $this->query($memberNumbers)->get();

        if ($memberNumbers !== null && $members->count() !== count($memberNumbers)) {
            throw ValidationException::withMessages([
                'member_numbers' => 'Die Auswahl enthält Mitglieder ohne offene Beiträge.',
            ]);
        }

        return $members;
    }

    public function member(int $memberNumber): ?Member
    {
        return $this->query([$memberNumber])->first();
    }

    /** @param Collection<int, Member> $members */
    public function html(Collection $members, bool $print = false): string
    {
        $settings = $this->clubSettings;
        $club = $settings->data();
        $giroCodes = $members->mapWithKeys(fn (Member $member): array => [
            $member->member_number => $this->giroCode($member, $club),
        ])->all();

        return view('payments.dunning-notices', [
            'members' => $members,
            'club' => $club,
            'logo' => $settings->logoDataUri(),
            'createdAt' => Clock::today(),
            'giroCodes' => $giroCodes,
            'print' => $print,
        ])->render();
    }

    /**
     * @param  list<int>|null  $memberNumbers
     * @return Builder<Member>
     */
    private function query(?array $memberNumbers = null): Builder
    {
        return Member::query()
            ->with(['contributionAccount.contributions' => fn ($query) => $query
                ->where('status', 'open')
                ->whereColumn('amount_cents', '>', 'paid_cents')
                ->orderBy('due_date')
                ->orderBy('id')])
            ->whereHas('contributionAccount.contributions', fn ($query) => $query
                ->where('status', 'open')
                ->whereColumn('amount_cents', '>', 'paid_cents'))
            ->when($memberNumbers !== null, fn ($query) => $query->whereIn('member_number', $memberNumbers))
            ->orderBy('last_name')
            ->orderBy('first_name');
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        return array_values($this->members()->map(function (Member $member): array {
            $contributions = $member->contributionAccount->contributions;
            $openCents = $contributions->sum(fn (Contribution $item): int => $item->remainingCents());
            $overdue = $contributions->filter(fn (Contribution $item): bool => $item->due_date->isBefore(Clock::today()));

            return [
                'member_number' => $member->member_number,
                'member_name' => $member->first_name.' '.$member->last_name,
                'email' => $member->email,
                'address_ready' => filled($member->street) && filled($member->postal_code) && filled($member->city),
                'open_count' => $contributions->count(),
                'open_cents' => $openCents,
                'overdue_count' => $overdue->count(),
                'overdue_cents' => $overdue->sum(fn (Contribution $item): int => $item->remainingCents()),
                'earliest_due_date' => $contributions->first()?->due_date->format('Y-m-d'),
            ];
        })->all());
    }

    /** @param Collection<int, Member> $members */
    public function pdf(Collection $members): string
    {
        return MemberReportWriter::pdf($this->html($members));
    }

    /**
     * @param  array<string, mixed>|null  $club
     * @return array{amount: string, recipient: string, iban: string, bic: string, purpose: string, image: string}|null
     */
    public function giroCode(Member $member, ?array $club = null): ?array
    {
        $club ??= $this->clubSettings->data();
        if (empty($club['iban']) || empty($club['name'])) {
            return null;
        }
        $openCents = $member->contributionAccount->contributions
            ->sum(fn (Contribution $item): int => $item->remainingCents());
        if ($openCents <= 0) {
            return null;
        }

        return app(GiroCode::class)->create(
            $openCents,
            (string) $club['name'],
            (string) $club['iban'],
            is_string($club['bic'] ?? null) ? $club['bic'] : null,
            $member->member_number,
        );
    }

    public function memberPdf(Member $member): string
    {
        $members = new Collection([$member]);

        return $this->pdf($members);
    }
}
