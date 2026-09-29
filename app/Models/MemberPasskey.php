<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A passkey for the member portal. Kept apart from the passkeys of user
 * accounts so a member credential can never sign in to the administration.
 *
 * @property int $id
 * @property int $member_id
 * @property string $name
 * @property string $credential_id
 * @property array<string, mixed> $credential
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 */
class MemberPasskey extends Model
{
    protected $fillable = ['name', 'credential_id', 'credential'];

    protected $hidden = ['credential'];

    protected function casts(): array
    {
        return [
            'credential' => 'array',
            'last_used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
