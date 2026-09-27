<?php

namespace App\Members;

use App\Models\Member;
use Illuminate\Support\Facades\DB;

final class MemberMandates
{
    public const DETAILS = [
        'iban', 'mandate_reference', 'mandate_signed_at',
        'account_holder_first_name', 'account_holder_last_name',
        'account_holder_street', 'account_holder_postal_code',
        'account_holder_city', 'account_holder_country',
    ];

    public function revoke(Member $member, string $reason): void
    {
        DB::table('member_documents')
            ->where('member_id', $member->getKey())
            ->where('kind', 'sepa')
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revocation_reason' => $reason,
                'updated_at' => now(),
            ]);
    }

    /** @return array<string, null> */
    public function clearedDetails(): array
    {
        return array_fill_keys(self::DETAILS, null);
    }
}
