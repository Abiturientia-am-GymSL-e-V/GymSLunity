<?php

declare(strict_types=1);

namespace App\Payments;

use App\Models\Contribution;
use App\Models\ContributionAccount;
use App\Models\ContributionBatch;
use App\Models\Member;
use App\Models\MemberFieldDefinition;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

final class CreateContributions
{
    public function __construct(private readonly ContributionLedger $ledger) {}

    /**
     * @param  array{period_start: string, period_end: string, due_date: string, description: string, amount_mode: string, amount?: string|int|float|null, membership_type?: string|null, payment_method?: string|null, honorary: string, tax_deductible: bool, filters?: list<array{key: string, value: string}>}  $data
     * @return array{created: int, skipped: int}
     */
    public function handle(User $actor, array $data): array
    {
        return DB::transaction(function () use ($actor, $data): array {
            $batch = ContributionBatch::query()->create([
                'actor_id' => $actor->id,
                'actor_name' => $actor->name,
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'due_date' => $data['due_date'],
                'description' => $data['description'],
                'criteria' => $data,
                'created_count' => 0,
                'skipped_count' => 0,
                'created_at' => now(),
            ]);

            $query = Member::query()
                ->where(fn (Builder $query) => $query->whereNull('joined_at')->orWhereDate('joined_at', '<=', $data['period_end']))
                ->where(fn (Builder $query) => $query->whereNull('left_at')->orWhereDate('left_at', '>=', $data['period_start']))
                ->where(fn (Builder $query) => $query->whereNull('deceased_at')->orWhereDate('deceased_at', '>=', $data['period_start']));
            if (! empty($data['membership_type'])) {
                $query->where('membership_type', $data['membership_type']);
            }
            if (! empty($data['payment_method'])) {
                $query->where('payment_method', $data['payment_method']);
            }
            if ($data['honorary'] === 'exclude') {
                $query->where('is_honorary', false);
            } elseif ($data['honorary'] === 'only') {
                $query->where('is_honorary', true);
            }
            $fields = MemberFieldDefinition::query()->whereIn('key', collect($data['filters'] ?? [])->pluck('key'))
                ->where('is_active', true)->where('filterable', true)->get()->keyBy('key');
            foreach ($data['filters'] ?? [] as $filter) {
                $field = $fields->get($filter['key']);
                if (! $field) {
                    continue;
                }
                $value = match ($field->type) {
                    'boolean' => $filter['value'] === '1',
                    'number' => (int) $filter['value'],
                    'decimal' => number_format((float) $filter['value'], 2, '.', ''),
                    default => $filter['value'],
                };
                $query->where($field->is_custom ? 'custom_values->'.$field->key : $field->key, $value);
            }

            $members = $query->with('contributionAccount')->orderBy('id')->get();
            $accountIds = $members->pluck('contributionAccount.id')->filter()->map(fn ($id): int => (int) $id)->sort()->values();
            $accounts = $accountIds->isEmpty()
                ? collect()
                : ContributionAccount::query()->whereKey($accountIds)->orderBy('id')->lockForUpdate()->get()->keyBy('member_id');
            if ($accounts->count() !== $members->count()) {
                throw new LogicException('Mindestens ein Mitglied besitzt kein Beitragskonto.');
            }
            $existing = Contribution::query()
                ->whereIn('account_id', $accountIds)
                ->where('kind', 'contribution')
                ->whereDate('period_start', $data['period_start'])
                ->whereDate('period_end', $data['period_end'])
                ->where('description', $data['description'])
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('account_id')
                ->flip();

            $created = 0;
            $skipped = 0;
            foreach ($members as $member) {
                $amountCents = $data['amount_mode'] === 'member'
                    ? Money::cents((string) ($member->sponsor_contribution ?? '0'))
                    : Money::cents((string) ($data['amount'] ?? '0'));
                if ($amountCents <= 0) {
                    $skipped++;

                    continue;
                }
                $account = $accounts->get($member->getKey());
                if (! $account instanceof ContributionAccount) {
                    throw new LogicException('Beitragskonto konnte nicht ermittelt werden.');
                }
                if ($existing->has($account->id)) {
                    $skipped++;

                    continue;
                }
                $account->setRelation('member', $member);
                $contribution = new Contribution([
                    'account_id' => $account->id,
                    'batch_id' => $batch->id,
                    'created_by' => $actor->id,
                    'kind' => 'contribution',
                    'description' => $data['description'],
                    'amount_cents' => $amountCents,
                    'paid_cents' => 0,
                    'period_start' => $data['period_start'],
                    'period_end' => $data['period_end'],
                    'due_date' => $data['due_date'],
                    'payment_method' => $member->payment_method,
                    'tax_deductible' => $data['tax_deductible'],
                    'status' => 'open',
                ]);
                $contribution->setRelation('account', $account);
                $contribution->save();
                $this->ledger->charge($contribution, $actor, metadata: ['batch_id' => $batch->id], lockedAccount: $account);
                $created++;
            }
            $batch->update(['created_count' => $created, 'skipped_count' => $skipped]);

            return compact('created', 'skipped');
        }, attempts: 3);
    }
}
