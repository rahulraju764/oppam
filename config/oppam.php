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
        // Contact details (emails, phone, address, hours) are admin-editable settings since P0.6
        // (App\Enums\SettingKey::Site*); read them through App\Data\Content\SiteContactData.
        // Rendered as a link only when set (no placeholder URLs).
        'map_url' => env('SITE_MAP_URL'),
        // Contact-page map embed (the template's Angamaly embed by default); omitted when empty.
        'map_embed_url' => env('SITE_MAP_EMBED_URL', 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d125655.32334806089!2d76.2986082794851!3d10.202660579800396!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3b080665e0bb9959%3A0x19b75e6b4e671ef1!2sAngamaly%2C%20Kerala!5e0!3m2!1sen!2sin!4v1780653618173!5m2!1sen!2sin'),
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

    // Photos & media (M11). Originals and horoscopes on the private disk (served only through
    // signed, audited routes); photo conversions on the public disk under unguessable uuid paths.
    // A01 / A03 time-boxed impersonation (PRD A01: 30 min). The admin panel hands the browser to
    // the member site with a single-use token valid for handoff_seconds, from the same IP only.
    'impersonation' => [
        'minutes' => 30,
        'handoff_seconds' => 60,
    ],

    'media' => [
        'private_disk' => env('MEDIA_PRIVATE_DISK', 'local'),      // production: s3-private
        'public_disk' => env('MEDIA_PUBLIC_DISK', 'public'),       // production: s3 (public bucket)
        'max_photos' => 10,                                        // profile photo + 9 more (M02 step 6)
        'photo_max_kb' => 10240,                                   // 10 MB
        'photo_min_px' => 400,                                     // shorter side
        'horoscope_max_kb' => 5120,
        'uploads_per_hour' => 30,                                  // per member, photos + horoscope
        'signed_url_minutes' => 5,                                 // horoscope downloads
        'watermark_font' => resource_path('fonts/DejaVuSans-Bold.ttf'),
    ],

    'sms' => [
        // log (local/testing) | msg91 (production). Bound in AppServiceProvider; `log` is refused in production.
        'driver' => env('SMS_DRIVER', 'log'),
    ],

    // Member / broker sign-in (M01, PRD §8.2). Security policy, so config rather than admin settings
    // (same reasoning as the admin block above). OTP send limits and TTL are A15 settings (SettingKey::Otp*).
    'auth' => [
        'otp_resend_seconds' => 30,              // minimum gap between two codes to one number
        'password_attempts' => 5,                // per login id, then locked for password_lockout_minutes
        'password_lockout_minutes' => 15,
        'ip_attempts_per_minute' => 30,          // password logins + OTP verifications from one IP
        'registrations_per_ip_per_hour' => 20,
        'remember_days' => 30,                   // "Stay logged in"
        'device_cookie' => 'oppam_device',       // random id, recognises a returning device (new-device alert)
        'referral_cookie' => 'oppam_ref',        // ?ref=BRK1042, 30-day first touch (R-M13-9; used from P7.2)
        'referral_cookie_days' => 30,
    ],

];
