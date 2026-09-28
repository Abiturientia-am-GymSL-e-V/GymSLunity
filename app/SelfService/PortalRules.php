<?php

declare(strict_types=1);

namespace App\SelfService;

use App\Configuration\ClubSettings;
use App\Models\Member;
use App\Support\FormOfAddress;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** What a member (or a guest applicant) may do in the portal. */
final class PortalRules
{
    public function __construct(private readonly ClubSettings $clubSettings) {}

    /** Contacts that never joined and former members may (re-)apply. */
    public function canJoin(Member $member): bool
    {
        if ($member->deceased_at !== null
            || DB::table('membership_applications')->where('member_id', $member->id)->whereNull('approved_at')->exists()) {
            return false;
        }

        return ($member->membership_type === 'Kontakt' && $member->joined_at === null)
            || ($member->joined_at !== null
                && $member->left_at !== null
                && ! $member->left_at->isFuture());
    }

    /** Guests need public joining enabled; known members must be allowed to (re-)apply. */
    public function mayJoin(?Member $member): bool
    {
        return $member ? $this->canJoin($member) : $this->clubSettings->enabled('public_join_enabled');
    }

    public function mayRequestCancellation(Member $member, bool $pendingCancellation): bool
    {
        return $member->hasActiveOrUpcomingMembership() && $member->left_at === null && ! $pendingCancellation;
    }

    /** Optimistic locking against concurrent edits by the administration. */
    public static function assertUnchanged(Member $member, int $version): void
    {
        if ($member->lock_version !== $version) {
            throw ValidationException::withMessages(['lock_version' => FormOfAddress::choose('Deine Daten wurden inzwischen geändert. Bitte lade die Seite neu.', 'Ihre Daten wurden inzwischen geändert. Bitte laden Sie die Seite neu.')]);
        }
    }
}
