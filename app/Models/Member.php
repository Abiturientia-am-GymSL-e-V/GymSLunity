<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\EncryptedString;
use App\Members\MemberFields;
use Carbon\CarbonImmutable;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $member_number
 * @property string $first_name
 * @property string|null $middle_name
 * @property string $last_name
 * @property string|null $email
 * @property string|null $street
 * @property string|null $postal_code
 * @property string|null $city
 * @property CarbonImmutable|null $birth_date
 * @property CarbonImmutable|null $joined_at
 * @property CarbonImmutable|null $left_at
 * @property CarbonImmutable|null $deceased_at
 * @property string|null $payment_method
 * @property string|null $iban
 * @property string|null $mandate_reference
 * @property CarbonImmutable|null $mandate_signed_at
 * @property string $mandate_type
 * @property string|null $account_holder_first_name
 * @property string|null $account_holder_last_name
 * @property string|null $sponsor_contribution
 * @property array<string, string|int|float|bool|null>|null $custom_values
 * @property ContributionAccount $contributionAccount
 */
class Member extends Model
{
    /** @use HasFactory<MemberFactory> */
    use HasFactory;

    /** @var list<string> */
    public const LIST_FIELDS = [
        'id', 'member_number', 'first_name', 'middle_name', 'last_name',
        'email', 'mobile_phone', 'street', 'postal_code', 'city', 'country',
        'birth_date', 'membership_type',
        'department_role', 'club_role', 'is_honorary', 'custom_values',
        'joined_at', 'left_at', 'deceased_at',
    ];

    /** @var list<string> */
    protected $fillable = [
        'member_number', 'first_name', 'middle_name', 'last_name',
        'email', 'mobile_phone', 'street', 'postal_code', 'city', 'country',
        'birth_date', 'membership_type',
        'department_role', 'club_role', 'is_honorary', 'custom_values',
        'joined_at', 'left_at', 'deceased_at',
        ...MemberFields::ADDITIONAL_FIELDS,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'member_number' => 'integer',
            'birth_date' => 'immutable_date:Y-m-d',
            'joined_at' => 'immutable_date:Y-m-d',
            'left_at' => 'immutable_date:Y-m-d',
            'deceased_at' => 'immutable_date:Y-m-d',
            'is_honorary' => 'boolean',
            'custom_values' => 'array',
            'iban' => EncryptedString::class,
            'mandate_signed_at' => 'immutable_date:Y-m-d',
            'sponsor_contribution' => 'decimal:2',
            'lock_version' => 'integer',
        ];
    }

    /** @return Attribute<string|null, string|null> */
    protected function mobilePhone(): Attribute
    {
        return Attribute::make(
            set: static function (?string $value): ?string {
                if ($value === null) {
                    return null;
                }

                return preg_replace('/^(\+\d{1,4}\s+)0+/', '$1', trim($value));
            },
        );
    }

    protected static function booted(): void
    {
        static::created(function (Member $member): void {
            $member->contributionAccount()->create(['balance_cents' => 0]);
        });
    }

    /**
     * Joined (possibly with a future entry date), not left and not deceased.
     * Unlike isCurrentMember(), an approved future entry already counts.
     */
    public function hasActiveOrUpcomingMembership(): bool
    {
        return $this->joined_at !== null
            && $this->deceased_at === null
            && ($this->left_at === null || $this->left_at->isFuture());
    }

    /** Joined (not in the future), not left and not deceased. */
    public function isCurrentMember(): bool
    {
        return $this->joined_at !== null && ! $this->joined_at->isFuture()
            && $this->deceased_at === null && ($this->left_at === null || $this->left_at->isFuture());
    }

    /** @return HasOne<ContributionAccount, $this> */
    public function contributionAccount(): HasOne
    {
        return $this->hasOne(ContributionAccount::class);
    }

    /** @return HasMany<MemberAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(MemberAssignment::class);
    }

    /** @return HasMany<MemberPasskey, $this> */
    public function passkeys(): HasMany
    {
        return $this->hasMany(MemberPasskey::class);
    }
}
