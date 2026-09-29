<?php

declare(strict_types=1);

namespace App\Demo;

use App\Configuration\UserRoles;
use App\Models\User;

final class DemoAccounts
{
    /** Administration accounts shared by all visitors of the public demo. */
    public const USERS = [
        ['email' => 'admin@example.org', 'name' => 'Demo-Administration', 'roles' => ['admin']],
        ['email' => 'vorstand@example.org', 'name' => 'Demo-Vorstand', 'roles' => ['vereinsverwaltung']],
        ['email' => 'kasse@example.org', 'name' => 'Demo-Kasse', 'roles' => ['bh', 'bv']],
        ['email' => 'mitgliederverwaltung@example.org', 'name' => 'Demo-Mitgliederverwaltung', 'roles' => ['mv']],
        ['email' => 'kassenpruefung@example.org', 'name' => 'Demo-Kassenprüfung', 'roles' => ['kp']],
    ];

    /** Member whose e-mail address opens the self-service portal. */
    public const MEMBER_EMAIL = 'mitglied@example.org';

    public static function enabled(): bool
    {
        return (bool) config('demo.enabled');
    }

    public static function isDemoUser(?User $user): bool
    {
        return self::enabled() && $user !== null
            && in_array(strtolower($user->email), array_column(self::USERS, 'email'), true);
    }

    /**
     * Credentials for the login page, or null outside the demo.
     *
     * @return array{password: string, resetAt: string, accounts: list<array{email: string, roles: string}>, memberEmail: string}|null
     */
    public static function publicInfo(): ?array
    {
        if (! self::enabled()) {
            return null;
        }

        return [
            'password' => (string) config('demo.password'),
            'resetAt' => (string) config('demo.reset_at'),
            'accounts' => array_map(fn (array $account): array => [
                'email' => $account['email'],
                'roles' => implode(', ', array_map(fn (string $role): string => UserRoles::LABELS[$role], $account['roles'])),
            ], self::USERS),
            'memberEmail' => self::MEMBER_EMAIL,
        ];
    }
}
