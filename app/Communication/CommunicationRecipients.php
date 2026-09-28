<?php

declare(strict_types=1);

namespace App\Communication;

use App\Members\MemberDirectory;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class CommunicationRecipients
{
    /** @param array<string, mixed> $filters
     * @return Builder<Member>
     */
    public static function query(array $filters): Builder
    {
        $query = MemberDirectory::query([
            'q' => $filters['q'],
            'membership' => $filters['membership'],
            'department_role' => $filters['department_role'],
            'club_role' => $filters['club_role'],
            'custom' => $filters['custom'],
            'sort' => 'name',
            'direction' => 'asc',
        ]);
        $today = CarbonImmutable::today()->toDateString();

        match ($filters['status']) {
            'active' => $query
                ->whereNotNull('joined_at')->whereDate('joined_at', '<=', $today)
                ->where(fn (Builder $builder) => $builder->whereNull('left_at')->orWhereDate('left_at', '>', $today))
                ->where(fn (Builder $builder) => $builder->whereNull('deceased_at')->orWhereDate('deceased_at', '>', $today)),
            'contacts' => $query->whereNull('joined_at')->whereNull('left_at')->whereNull('deceased_at'),
            'former' => $query->where(fn (Builder $builder) => $builder
                ->whereDate('left_at', '<=', $today)->orWhereDate('deceased_at', '<=', $today)),
            'future' => $query->whereDate('joined_at', '>', $today),
            default => null,
        };

        foreach (['payment_method', 'city'] as $column) {
            if ($filters[$column] !== '') {
                $query->where($column, $filters[$column]);
            }
        }
        if ($filters['gender'] === '__none__') {
            $query->where(fn (Builder $builder) => $builder->whereNull('gender')->orWhere('gender', ''));
        } elseif ($filters['gender'] !== '') {
            $query->where('gender', $filters['gender']);
        }
        if ($filters['honorary'] !== '') {
            $query->where('is_honorary', $filters['honorary'] === 'yes');
        }
        if ($filters['email_status'] === 'with') {
            self::present($query, 'email');
        } elseif ($filters['email_status'] === 'without') {
            self::missing($query, 'email');
        }
        if ($filters['address_status'] === 'complete') {
            foreach (['street', 'postal_code', 'city'] as $column) {
                self::present($query, $column);
            }
        } elseif ($filters['address_status'] === 'incomplete') {
            $query->where(function (Builder $builder): void {
                foreach (['street', 'postal_code', 'city'] as $column) {
                    $builder->orWhereNull($column)->orWhere($column, '');
                }
            });
        }
        if ($filters['joined_from'] !== '') {
            $query->whereDate('joined_at', '>=', $filters['joined_from']);
        }
        if ($filters['joined_to'] !== '') {
            $query->whereDate('joined_at', '<=', $filters['joined_to']);
        }

        return $query;
    }

    /** @param Builder<Member> $query */
    private static function present(Builder $query, string $column): void
    {
        $query->whereNotNull($column)->where($column, '<>', '');
    }

    /** @param Builder<Member> $query */
    private static function missing(Builder $query, string $column): void
    {
        $query->where(fn (Builder $builder) => $builder->whereNull($column)->orWhere($column, ''));
    }
}
