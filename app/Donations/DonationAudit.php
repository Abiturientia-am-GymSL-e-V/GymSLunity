<?php

declare(strict_types=1);

namespace App\Donations;

use App\Models\Donation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DonationAudit
{
    /** @param array<string, mixed> $payload */
    public static function record(User $actor, string $event, array $payload, ?Donation $donation = null): void
    {
        $head = DB::table('donation_audit_heads')->where('id', 1)->lockForUpdate()->first();
        $previousHash = $head?->last_hash;
        $createdAt = now();
        $canonical = json_encode([
            'donation_id' => $donation?->getKey(),
            'actor_id' => $actor->getKey(),
            'actor_name' => $actor->name,
            'event' => $event,
            'payload' => $payload,
            'previous_hash' => $previousHash,
            'created_at' => $createdAt->toISOString(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $canonical);

        DB::table('donation_audits')->insert([
            'donation_id' => $donation?->getKey(),
            'actor_id' => $actor->getKey(),
            'actor_name' => $actor->name,
            'event' => $event,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'previous_hash' => $previousHash,
            'event_hash' => $hash,
            'created_at' => $createdAt,
        ]);
        DB::table('donation_audit_heads')->where('id', 1)->update(['last_hash' => $hash]);
    }
}
