<?php

declare(strict_types=1);

return [
    // Public demo instance: shows the demo credentials, allows the shared demo
    // accounts to skip the second factor and resets all data every night.
    // Never enable this on an instance with real club data.
    'enabled' => (bool) env('DEMO_MODE', false),

    // Shared password of all demo accounts. It is displayed on the login page.
    'password' => env('DEMO_PASSWORD', 'Demo-Passwort-2026'),

    // Local time (APP_DISPLAY_TIMEZONE) of the nightly reset.
    'reset_at' => env('DEMO_RESET_AT', '00:00'),
];
