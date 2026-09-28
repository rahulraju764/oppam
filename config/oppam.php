<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Oppam application configuration (non-secret)
|--------------------------------------------------------------------------
| Business-tunable values (limits, expiry days, thresholds) do NOT live here —
| they are admin-editable settings (PRD §11 A15). This file holds deployment
| wiring only. Secrets stay in .env and are read only through config files.
*/

return [

    // Public + member site and broker portal host (PRD §5). Local: localhost (open http://localhost:8000,
    // not 127.0.0.1). Pinning the public routes to this host keeps them off the admin domain.
    'app_domain' => env('APP_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'),

    // Admin panel host (PRD §5, §8.1). Local: admin.localhost — browsers resolve *.localhost to 127.0.0.1.
    'admin_domain' => env('ADMIN_DOMAIN', 'admin.localhost'),

    // Dates are stored in UTC and displayed in IST (CLAUDE.md conventions).
    'display_timezone' => env('APP_DISPLAY_TIMEZONE', 'Asia/Kolkata'),

    // Replaces the template's SITE_LIVE: while false every page is noindex (PRD §6.1).
    'indexable' => (bool) env('APP_INDEXABLE', false),

    'sms' => [
        // log (local/testing) | msg91 (production). Bound in AppServiceProvider once SmsGateway exists (P1.1).
        'driver' => env('SMS_DRIVER', 'log'),
    ],

];
