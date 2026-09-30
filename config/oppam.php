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

    // Site identity shown in the footer and contact page. Moves to admin-editable settings in P0.6
    // (A15 "Site settings"); until then these are the defaults.
    'site' => [
        'name' => 'Oppam Matrimony',
        'tagline' => 'Two hearts, one journey',
        // One source for the footer and the contact page (the template listed a Dubai address in
        // the footer and this Kerala office on contact.php). Placeholder values from the template.
        'address' => ['1st floor 272-3, near St George Basilica church,', 'Angamaly, Kerala 683572'],
        'hours' => 'Monday – Saturday, 9:30am – 6:30pm',
        // Rendered as a link only when set (no placeholder URLs).
        'map_url' => env('SITE_MAP_URL'),
        // Contact-page map embed (the template's Angamaly embed by default); omitted when empty.
        'map_embed_url' => env('SITE_MAP_EMBED_URL', 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d125655.32334806089!2d76.2986082794851!3d10.202660579800396!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3b080665e0bb9959%3A0x19b75e6b4e671ef1!2sAngamaly%2C%20Kerala!5e0!3m2!1sen!2sin!4v1780653618173!5m2!1sen!2sin'),
        // dial string => display (the template's placeholder number, dialled exactly as it had it).
        'phones' => ['089538812873' => '0895 - 3881 - 2873'],
        'emails' => ['support@oppam.in', 'info@oppam.in'],
        // Only rendered when set — the template's href="#" placeholders are not carried over.
        'social' => [
            'facebook' => env('SOCIAL_FACEBOOK_URL'),
            'twitter' => env('SOCIAL_TWITTER_URL'),
            'instagram' => env('SOCIAL_INSTAGRAM_URL'),
            'linkedin' => env('SOCIAL_LINKEDIN_URL'),
            'youtube' => env('SOCIAL_YOUTUBE_URL'),
        ],
    ],

    // Admin panel security policy (PRD A01, §8.1). Deployment-level security settings, not
    // business rules, so they live here (env-overridable) rather than in admin-editable settings.
    'admin' => [
        'session_cookie' => env('ADMIN_SESSION_COOKIE', 'oppam_admin_session'),
        'idle_timeout_minutes' => (int) env('ADMIN_IDLE_TIMEOUT', 30),
        'absolute_timeout_minutes' => (int) env('ADMIN_ABSOLUTE_TIMEOUT', 720),   // 12 h
        'max_login_attempts' => 3,              // then locked for lockout_minutes (per email, known or not)
        'max_two_factor_attempts' => 5,         // then account locked + super admins alerted
        'lockout_minutes' => 30,
        'pending_login_minutes' => 10,          // password accepted, 2FA not yet done
        'invitation_hours' => 72,               // single-use staff invitation link
        'recovery_codes' => 10,
        // LOCAL demo admins only (AdminUsersSeeder). Never set in staging/production.
        'seed_password' => env('ADMIN_SEED_PASSWORD'),
        // Comma-separated IPs / CIDR ranges allowed to reach the admin panel; empty = no restriction.
        'ip_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('ADMIN_IP_ALLOWLIST', ''))))),
    ],

    'sms' => [
        // log (local/testing) | msg91 (production). Bound in AppServiceProvider once SmsGateway exists (P1.1).
        'driver' => env('SMS_DRIVER', 'log'),
    ],

];
