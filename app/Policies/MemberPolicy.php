<?php

namespace App\Policies;

use App\Models\Member;
use App\Models\User;

class MemberPolicy
{
    public function view(User $user, Member $member): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Member $member): bool
    {
        return $user->is_active && count(array_intersect($user->roles ?? [], ['admin', 'vereinsverwaltung', 'mv'])) > 0;
    }

    public function viewAny(User $user): bool
    {
        return $user->is_active && count(array_intersect($user->roles ?? [], [
            'admin', 'vereinsverwaltung', 'mv', 'auditor',
        ])) > 0;
    }
}
