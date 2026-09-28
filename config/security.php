<?php

declare(strict_types=1);

return [
    'privileged_roles' => ['admin', 'vereinsverwaltung', 'mv', 'auditor', 'bh', 'bv', 'kp'],

    // Privileged sessions are intentionally short-lived and cannot be restored
    // through a persistent "remember me" cookie.
    'inactivity_timeout' => (int) env('SECURITY_INACTIVITY_TIMEOUT', 30 * 60),

    'audit_retention_days' => (int) env('SECURITY_AUDIT_RETENTION_DAYS', 730),
    'audit_identifier_retention_days' => (int) env('SECURITY_AUDIT_IDENTIFIER_RETENTION_DAYS', 90),

    'headers' => [
        'enabled' => env('SECURITY_HEADERS_ENABLED', true),
        'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
    ],
];
