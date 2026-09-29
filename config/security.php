<?php

declare(strict_types=1);

return [
    'privileged_roles' => ['admin', 'vereinsverwaltung', 'mv', 'auditor', 'bh', 'bv', 'kp'],

    // Privileged sessions are intentionally short-lived and cannot be restored
    // through a persistent "remember me" cookie.
    'inactivity_timeout' => (int) env('SECURITY_INACTIVITY_TIMEOUT', 30 * 60),

    // Exports, backups, user management and mail settings ask for the
    // password again when it was last confirmed longer ago than this.
    'reconfirm_seconds' => (int) env('SECURITY_RECONFIRM_SECONDS', 15 * 60),

    // Reverse proxies whose X-Forwarded-* headers are trusted, comma separated
    // (e.g. "127.0.0.1" or "*" behind a managed load balancer). Without this,
    // rate limits and audit entries see the proxy address instead of clients.
    'trusted_proxies' => env('TRUSTED_PROXIES'),

    'audit_retention_days' => (int) env('SECURITY_AUDIT_RETENTION_DAYS', 730),
    'audit_identifier_retention_days' => (int) env('SECURITY_AUDIT_IDENTIFIER_RETENTION_DAYS', 90),

    'headers' => [
        'enabled' => env('SECURITY_HEADERS_ENABLED', true),
        'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
    ],
];
