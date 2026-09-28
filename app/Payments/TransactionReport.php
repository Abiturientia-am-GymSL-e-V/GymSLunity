<?php

declare(strict_types=1);

namespace App\Payments;

use App\Models\ContributionTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class TransactionReport
{
    /** @var array<string, string> */
    public const KIND_LABELS = [
        'contribution' => 'Beitrag',
        'return_debit_fee' => 'Rücklastschriftgebühr',
        'manual_payment' => 'Manuelle Zahlung',
        'bank_payment' => 'Bankimport',
        'sepa_payment' => 'SEPA-Zahlung',
        'bank_return_debit' => 'Rücklastschrift',
        'manual_charge' => 'Manuelle Forderung',
        'booking' => 'Ressourcenbuchung',
        'booking_refund' => 'Buchungsstorno',
    ];

    /**
     * @param  array{from: string, to: string, q?: string|null, kind?: string|null, direction?: string|null}  $filters
     * @return Collection<int, ContributionTransaction>
     */
    public function entries(array $filters): Collection
    {
        $query = ContributionTransaction::query()
            ->with('account.member:id,member_number,first_name,last_name')
            ->whereBetween('booking_date', [$filters['from'], $filters['to']]);

        $search = trim((string) ($filters['q'] ?? ''));
        $terms = preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($terms as $term) {
            $query->where(function (Builder $query) use ($term): void {
                $pattern = '%'.$term.'%';
                $query->where('description', 'like', $pattern)
                    ->orWhere('reference', 'like', $pattern)
                    ->orWhere('actor_name', 'like', $pattern)
                    ->orWhereHas('account.member', function (Builder $members) use ($pattern, $term): void {
                        $members->where('first_name', 'like', $pattern)
                            ->orWhere('last_name', 'like', $pattern);
                        if (ctype_digit($term)) {
                            $members->orWhere('member_number', (int) $term);
                        }
                    });
            });
        }

        if (filled($filters['kind'] ?? null) && $filters['kind'] !== 'all') {
            $query->where('kind', $filters['kind']);
        }
        if (($filters['direction'] ?? null) === 'charge') {
            $query->where('amount_cents', '>', 0);
        } elseif (($filters['direction'] ?? null) === 'credit') {
            $query->where('amount_cents', '<', 0);
        }

        return $query->latest('booking_date')->latest('id')->get();
    }

    public function kindLabel(string $kind): string
    {
        return self::KIND_LABELS[$kind] ?? $kind;
    }
}
