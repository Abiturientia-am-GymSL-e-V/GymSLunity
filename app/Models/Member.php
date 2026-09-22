<?php

namespace App\Models;

use App\Members\MemberFields;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** @property array<string, string|int|float|bool|null>|null $custom_values */
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
            'mandate_signed_at' => 'immutable_date:Y-m-d',
            'sponsor_contribution' => 'decimal:2',
            'lock_version' => 'integer',
        ];
    }
}
