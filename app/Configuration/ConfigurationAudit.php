<?php

namespace App\Configuration;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ConfigurationAudit
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public static function record(User $actor, string $subject, array $before, array $after): void
    {
        if ($before === $after) {
            return;
        }
        DB::table('configuration_changes')->insert([
            'actor_id' => $actor->getKey(), 'actor_name' => $actor->name, 'subject' => $subject,
            'before' => json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
