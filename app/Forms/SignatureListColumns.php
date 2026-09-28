<?php

declare(strict_types=1);

namespace App\Forms;

use App\Members\MemberFields;
use Illuminate\Support\Collection;

/** Member fields that may be printed as columns on a signature list. */
final class SignatureListColumns
{
    /** Bank and mandate details never belong on a list passed around a room. */
    private const EXCLUDED = [
        'iban', 'mandate_reference', 'mandate_signed_at', 'mandate_type', 'account_holder_first_name',
        'account_holder_last_name', 'account_holder_street', 'account_holder_postal_code',
        'account_holder_city', 'account_holder_country', 'payment_method',
    ];

    /** @return Collection<string, array<string, mixed>> directory fields keyed by field key */
    public static function fields(): Collection
    {
        return collect(MemberFields::directoryFields())
            ->reject(fn (array $field): bool => in_array($field['key'], self::EXCLUDED, true))
            ->keyBy('key');
    }

    /** @return list<string> every selectable column key */
    public static function keys(): array
    {
        return [...self::fields()->keys()->all(), 'member_number', 'signature'];
    }
}
