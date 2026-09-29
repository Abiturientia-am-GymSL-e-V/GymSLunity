<?php

declare(strict_types=1);

namespace App\Bookings;

use App\Configuration\ClubSettings;
use App\Models\BookingResource;
use App\Models\ContributionAccount;
use App\Models\ContributionTransaction;
use App\Models\Member;
use App\Models\MemberFieldDefinition;
use App\Models\ResourceBooking;
use App\Models\User;
use App\Support\Clock;
use App\Support\FormOfAddress;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class BookingManager
{
    public function __construct(private readonly ClubSettings $clubSettings) {}

    /** @param array<string, mixed> $data
     * @return list<ResourceBooking>
     */
    public function create(BookingResource $resource, ?Member $member, array $data, ?User $actor = null, bool $manual = false): array
    {
        if (! $resource->is_active) {
            throw ValidationException::withMessages(['resource_id' => 'Diese Ressource ist derzeit nicht buchbar.']);
        }
        $start = $this->localDateTime($data['starts_at']);
        $end = $this->localDateTime($data['ends_at']);
        if ($end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages(['ends_at' => 'Das Ende muss nach dem Beginn liegen.']);
        }
        if (! $manual && (! $member || ! $this->canRequest($resource, $member))) {
            throw ValidationException::withMessages(['resource_id' => FormOfAddress::choose('Diese Ressource kann mit deiner Mitgliedschaft nicht gebucht werden.', 'Diese Ressource kann mit Ihrer Mitgliedschaft nicht gebucht werden.')]);
        }

        $recurrence = (string) ($data['recurrence'] ?? 'none');
        $recurrenceInterval = max(1, (int) ($data['recurrence_interval'] ?? 1));
        $occurrences = $recurrence === 'none' ? 1 : (int) ($data['occurrences'] ?? 1);
        $seriesId = $occurrences > 1 ? (string) Str::uuid() : null;
        $automaticallyApproved = $member !== null && $this->autoApproved($resource, $member);
        $status = $manual || $automaticallyApproved ? 'confirmed' : 'requested';
        $ranges = [];
        for ($index = 0; $index < $occurrences; $index++) {
            $occurrenceStart = match ($recurrence) {
                'daily' => $start->addDays($index * $recurrenceInterval),
                'weekly' => $start->addWeeks($index * $recurrenceInterval),
                'monthly' => $start->addMonthsNoOverflow($index * $recurrenceInterval),
                default => $start,
            };
            $occurrenceEnd = $occurrenceStart->addSeconds($start->diffInSeconds($end));
            $this->assertAvailable($resource, $occurrenceStart, $occurrenceEnd);
            $ranges[] = [$occurrenceStart, $occurrenceEnd];
        }

        return DB::transaction(function () use ($resource, $member, $data, $actor, $manual, $status, $seriesId, $ranges): array {
            $relatedIds = $resource->relatedIds();
            BookingResource::query()->whereIn('id', $relatedIds)->orderBy('id')->lockForUpdate()->get();
            $locked = BookingResource::query()->whereKey($resource->id)->firstOrFail();
            $created = [];
            foreach ($ranges as $index => [$start, $end]) {
                $this->assertAvailable($locked, $start, $end);
                $booking = ResourceBooking::query()->create([
                    'resource_id' => $locked->id,
                    'member_id' => $member?->id,
                    'requester_name' => $member ? trim($member->first_name.' '.$member->last_name) : trim((string) ($data['requester_name'] ?? '')),
                    'title' => $data['title'],
                    'notes' => ($data['notes'] ?? null) ?: null,
                    'starts_at' => $start,
                    'ends_at' => $end,
                    'series_id' => $seriesId,
                    'occurrence' => $index + 1,
                    'status' => $status,
                    'price_cents' => $this->price($locked, $start, $end),
                    'created_by' => $actor?->id,
                    'created_by_name' => $actor === null ? ($manual ? 'Verwaltung' : 'Mitgliederportal') : $actor->name,
                    'decided_by' => $status === 'confirmed' ? $actor?->id : null,
                    'decided_by_name' => $status === 'confirmed' ? ($actor === null ? 'Automatische Freigabe' : $actor->name) : null,
                    'decided_at' => $status === 'confirmed' ? now() : null,
                ]);
                if ($status === 'confirmed') {
                    $booking->setRelation('resource', $locked);
                    if ($member) {
                        $booking->setRelation('member', $member);
                    }
                    if ($this->isChargeDue($booking)) {
                        $this->charge($booking, $member, $actor, $manual ? 'Manuelle Buchung' : 'Automatische Freigabe');
                    }
                }
                $created[] = $booking->fresh();
            }

            return $created;
        }, attempts: 3);
    }

    public function approve(ResourceBooking $booking, User $actor): void
    {
        DB::transaction(function () use ($booking, $actor): void {
            // Lock the resource family like create(), so two approvals of
            // overlapping requests cannot both pass the availability check.
            $resource = BookingResource::query()->whereKey($booking->resource_id)->firstOrFail();
            BookingResource::query()->whereIn('id', $resource->relatedIds())->orderBy('id')->lockForUpdate()->get();
            $current = ResourceBooking::query()->with(['resource', 'member'])->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'requested') {
                throw ValidationException::withMessages(['booking' => 'Diese Anfrage wurde bereits bearbeitet.']);
            }
            $this->assertAvailable($current->resource, $current->starts_at, $current->ends_at, $current->id);
            $current->update([
                'status' => 'confirmed', 'decided_by' => $actor->id,
                'decided_by_name' => $actor->name, 'decided_at' => now(),
            ]);
            if ($this->isChargeDue($current)) {
                $this->charge($current, $current->member, $actor, 'Buchung bestätigt');
            }
        }, attempts: 3);
    }

    public function reject(ResourceBooking $booking, User $actor): void
    {
        $this->finish($booking, $actor, 'rejected');
    }

    public function cancel(ResourceBooking $booking, ?User $actor = null): void
    {
        DB::transaction(function () use ($booking, $actor): void {
            $current = ResourceBooking::query()->with('resource')->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if (! in_array($current->status, ['requested', 'confirmed'], true)) {
                throw ValidationException::withMessages(['booking' => 'Dieser Termin kann nicht mehr storniert werden.']);
            }
            if (! $current->ends_at->isFuture()) {
                throw ValidationException::withMessages(['booking' => 'Vergangene Buchungstermine können nicht storniert werden.']);
            }
            if ($actor === null && ! $this->canMemberCancel($current)) {
                throw ValidationException::withMessages(['booking' => 'Die Stornierungsfrist für diesen Termin ist bereits abgelaufen.']);
            }
            $current->update([
                'status' => 'cancelled', 'decided_by' => $actor?->id,
                'decided_by_name' => $actor === null ? 'Mitgliederportal' : $actor->name, 'decided_at' => now(),
            ]);
            $this->refund($current, $actor);
        }, attempts: 3);
    }

    public function cancelScope(ResourceBooking $booking, string $scope, ?User $actor = null): int
    {
        $bookings = $scope === 'series' && $booking->series_id !== null
            ? ResourceBooking::query()->where('series_id', $booking->series_id)->whereIn('status', ['requested', 'confirmed'])->where('ends_at', '>', now())->orderBy('occurrence')->get()
            : collect([$booking]);
        // All or nothing: a failing occurrence must not leave half a series cancelled.
        DB::transaction(function () use ($bookings, $actor): void {
            foreach ($bookings as $item) {
                $this->cancel($item, $actor);
            }
        }, attempts: 3);

        return $bookings->count();
    }

    /** @param array<string, mixed> $data */
    public function update(ResourceBooking $booking, array $data): int
    {
        $start = $this->localDateTime($data['starts_at']);
        $end = $this->localDateTime($data['ends_at']);
        if ($end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages(['ends_at' => 'Das Ende muss nach dem Beginn liegen.']);
        }
        $resource = BookingResource::query()->whereKey((int) $data['resource_id'])->firstOrFail();
        $targets = $data['scope'] === 'series' && $booking->series_id !== null
            ? ResourceBooking::query()->where('series_id', $booking->series_id)->whereIn('status', ['requested', 'confirmed'])->orderBy('occurrence')->get()
            : collect([$booking]);
        $exceptIds = array_values($targets->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $delta = $booking->starts_at->diffInSeconds($start, false);
        $duration = $start->diffInSeconds($end);

        return DB::transaction(function () use ($targets, $resource, $data, $start, $end, $exceptIds, $delta, $duration): int {
            BookingResource::query()->whereIn('id', $resource->relatedIds())->orderBy('id')->lockForUpdate()->get();
            foreach ($targets as $target) {
                $targetStart = count($targets) > 1 ? $target->starts_at->addSeconds($delta) : $start;
                $targetEnd = count($targets) > 1 ? $targetStart->addSeconds($duration) : $end;
                $this->assertAvailable($resource, $targetStart, $targetEnd, $exceptIds);
                $attributes = [
                    'resource_id' => $resource->id,
                    'title' => $data['title'],
                    'notes' => ($data['notes'] ?? null) ?: null,
                    'starts_at' => $targetStart,
                    'ends_at' => $targetEnd,
                ];
                if ($target->charge_transaction_id === null) {
                    $attributes['price_cents'] = $this->price($resource, $targetStart, $targetEnd);
                }
                $target->update($attributes);
            }

            return $targets->count();
        }, attempts: 3);
    }

    /** @return array<string, string> active membership types (value => label) */
    public function membershipTypes(): array
    {
        $field = MemberFieldDefinition::query()->where('key', 'membership_type')->first();
        if (! $field) {
            return Member::query()->distinct()->orderBy('membership_type')->pluck('membership_type', 'membership_type')->filter()->all();
        }

        return collect($field->options)->where('active', true)->pluck('label', 'value')->all();
    }

    /** @return list<array{key: string, label: string, options: list<array{value: string, label: string}>}> */
    public function memberFields(): array
    {
        $result = [];
        foreach (MemberFieldDefinition::query()->where('is_active', true)->whereIn('type', ['select', 'boolean'])->orderBy('position')->get() as $field) {
            $options = $field->type === 'boolean'
                ? [['value' => '1', 'label' => 'Ja'], ['value' => '0', 'label' => 'Nein']]
                : array_values(collect($field->options)->filter(fn (array $option): bool => $option['active'])->map(
                    fn (array $option): array => ['value' => $option['value'], 'label' => $option['label']],
                )->all());
            if ($options !== []) {
                $result[] = ['key' => $field->key, 'label' => $field->label, 'options' => $options];
            }
        }

        return $result;
    }

    public function canRequest(BookingResource $resource, Member $member): bool
    {
        $rules = $resource->access_rules ?? [];
        if ($rules !== []) {
            return $resource->is_active && collect($rules)->contains(fn (array $rule): bool => $this->matches($member, $rule));
        }
        $allowed = $resource->allowed_membership_types ?? [];

        return $resource->is_active && ($allowed === [] || in_array($member->membership_type, $allowed, true));
    }

    private function autoApproved(BookingResource $resource, Member $member): bool
    {
        $rules = $resource->auto_approve_rules ?? [];
        if ($rules !== []) {
            return collect($rules)->contains(fn (array $rule): bool => $this->matches($member, $rule));
        }

        return in_array($member->membership_type, $resource->auto_approve_membership_types ?? [], true);
    }

    public function willAutoApprove(BookingResource $resource, Member $member): bool
    {
        return $this->autoApproved($resource, $member);
    }

    /** Post every confirmed member charge whose cancellation deadline has passed. */
    public function chargeDue(): int
    {
        $count = 0;
        ResourceBooking::query()->with(['resource', 'member'])
            ->where('status', 'confirmed')->whereNotNull('member_id')
            ->whereNull('charge_transaction_id')->where('price_cents', '>', 0)
            ->where('starts_at', '<=', now()->addMinutes($this->cancellationNoticeMinutes()))
            ->orderBy('id')->chunkById(100, function ($bookings) use (&$count): void {
                foreach ($bookings as $booking) {
                    DB::transaction(function () use ($booking, &$count): void {
                        $current = ResourceBooking::query()->with(['resource', 'member'])->lockForUpdate()->findOrFail($booking->id);
                        if ($current->status === 'confirmed' && $current->charge_transaction_id === null && $this->isChargeDue($current)) {
                            $this->charge($current, $current->member, null, 'Stornierungsfrist abgelaufen');
                            $count++;
                        }
                    });
                }
            });

        return $count;
    }

    public function canMemberCancel(ResourceBooking $booking): bool
    {
        return in_array($booking->status, ['requested', 'confirmed'], true)
            && $booking->ends_at->isFuture()
            && now()->lt($booking->starts_at->subMinutes($this->cancellationNoticeMinutes()));
    }

    public function isChargeDue(ResourceBooking $booking): bool
    {
        return $booking->series_id === null
            || now()->greaterThanOrEqualTo($booking->starts_at->subMinutes($this->cancellationNoticeMinutes()));
    }

    public function cancellationNoticeMinutes(): int
    {
        $value = max(0, (int) $this->clubSettings->get('booking_cancellation_notice_value', 24));

        return $value * match ($this->clubSettings->get('booking_cancellation_notice_unit', 'hours')) {
            'days' => 1440,
            'minutes' => 1,
            default => 60,
        };
    }

    private function finish(ResourceBooking $booking, User $actor, string $status): void
    {
        $updated = ResourceBooking::query()->whereKey($booking->id)->where('status', 'requested')->update([
            'status' => $status, 'decided_by' => $actor->id,
            'decided_by_name' => $actor->name, 'decided_at' => now(),
        ]);
        if ($updated !== 1) {
            throw ValidationException::withMessages(['booking' => 'Diese Anfrage wurde bereits bearbeitet.']);
        }
    }

    /** @param int|list<int>|null $except */
    private function assertAvailable(BookingResource $resource, CarbonImmutable $start, CarbonImmutable $end, int|array|null $except = null): void
    {
        $ids = $resource->relatedIds();
        $conflict = ResourceBooking::query()
            ->whereIn('resource_id', $ids)
            ->whereIn('status', ['requested', 'confirmed'])
            ->when(is_int($except), fn ($query) => $query->where('id', '!=', $except))
            ->when(is_array($except) && $except !== [], fn ($query) => $query->whereNotIn('id', $except))
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->exists();
        if ($conflict) {
            throw ValidationException::withMessages(['starts_at' => 'Die Ressource oder eine verbundene Teilressource ist in diesem Zeitraum bereits belegt.']);
        }
    }

    private function price(BookingResource $resource, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $seconds = max(1, $start->diffInSeconds($end));

        if ($resource->price_mode === 'duration' && ($resource->pricing_rules ?? []) !== []) {
            $durationMinutes = (int) ceil($seconds / 60);
            $rule = collect($resource->pricing_rules)->sortBy(fn (array $rule): int => $this->ruleMinutes($rule, 'from'))->last(
                fn (array $rule): bool => $this->ruleMinutes($rule, 'from') <= $durationMinutes,
            ) ?? collect($resource->pricing_rules)->sortBy(fn (array $rule): int => $this->ruleMinutes($rule, 'from'))->first();
            $unitMinutes = max(1, $this->ruleMinutes($rule, 'unit'));

            return (int) ceil($durationMinutes / $unitMinutes) * (int) $rule['price_cents'];
        }

        return match ($resource->price_mode) {
            'once' => $resource->price_cents,
            'hour' => (int) ceil($seconds / 3600) * $resource->price_cents,
            'day' => (int) ceil($seconds / 86400) * $resource->price_cents,
            default => 0,
        };
    }

    /** @param array{field_key: string, value: string} $rule */
    private function matches(Member $member, array $rule): bool
    {
        $field = $rule['field_key'];
        $value = str_starts_with($field, 'custom_')
            ? ($member->custom_values[$field] ?? null)
            : $member->getAttribute($field);

        return (string) (is_bool($value) ? (int) $value : $value) === $rule['value'];
    }

    /** @param array<string, mixed> $rule */
    private function ruleMinutes(array $rule, string $prefix): int
    {
        $value = (int) $rule[$prefix.'_value'];

        return $value * match ($rule[$prefix] ?? $rule[$prefix.'_unit'] ?? 'minutes') {
            'days' => 1440,
            'hours' => 60,
            default => 1,
        };
    }

    private function charge(ResourceBooking $booking, ?Member $member, ?User $actor, string $reason): void
    {
        if (! $member || $booking->price_cents <= 0 || $booking->charge_transaction_id) {
            return;
        }
        $account = ContributionAccount::query()->firstOrCreate(['member_id' => $member->id], ['balance_cents' => 0]);
        $account = ContributionAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
        $transaction = ContributionTransaction::query()->create([
            'account_id' => $account->id, 'actor_id' => $actor?->id,
            'actor_name' => $actor === null ? 'Buchungssystem' : $actor->name, 'kind' => 'booking',
            'amount_cents' => $booking->price_cents,
            'booking_date' => $booking->starts_at->setTimezone(config('app.display_timezone'))->toDateString(),
            'reference' => 'BUCHUNG-'.$booking->id,
            'description' => 'Buchung '.$booking->resource->name.': '.$booking->title,
            'metadata' => ['booking_id' => $booking->id, 'reason' => $reason], 'created_at' => now(),
        ]);
        $account->update(['balance_cents' => $account->balance_cents + $booking->price_cents]);
        $booking->update(['charge_transaction_id' => $transaction->id]);
    }

    private function refund(ResourceBooking $booking, ?User $actor): void
    {
        if (! $booking->member_id || ! $booking->charge_transaction_id || $booking->refund_transaction_id) {
            return;
        }
        $account = ContributionAccount::query()->where('member_id', $booking->member_id)->lockForUpdate()->firstOrFail();
        $transaction = ContributionTransaction::query()->create([
            'account_id' => $account->id, 'actor_id' => $actor?->id,
            'actor_name' => $actor === null ? 'Buchungssystem' : $actor->name, 'kind' => 'booking_refund',
            'amount_cents' => -$booking->price_cents, 'booking_date' => Clock::todayString(),
            'reference' => 'STORNO-'.$booking->id,
            'description' => 'Storno Buchung '.$booking->resource->name.': '.$booking->title,
            'metadata' => ['booking_id' => $booking->id, 'charge_transaction_id' => $booking->charge_transaction_id], 'created_at' => now(),
        ]);
        $account->update(['balance_cents' => $account->balance_cents - $booking->price_cents]);
        $booking->update(['refund_transaction_id' => $transaction->id]);
    }

    private function localDateTime(string $value): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d\TH:i', $value, config('app.display_timezone'))->utc();
    }
}
