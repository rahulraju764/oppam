<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    // SMS / OTP (PRD §4). SMS_DRIVER in config/oppam.php picks the gateway.
    'msg91' => [
        'auth_key' => env('MSG91_AUTH_KEY'),
        'sender_id' => env('MSG91_SENDER_ID', 'OPPAMM'),
        'otp_template_id' => env('MSG91_OTP_TEMPLATE_ID'),
        // DLT template: "You already have an Oppam Matrimony account. Log in instead." (M01)
        'account_exists_template_id' => env('MSG91_ACCOUNT_EXISTS_TEMPLATE_ID'),
        // DLT templates for member notifications (M08), keyed by notification event. An event
        // without an approved template is never sent by SMS (SmsChannel reports and skips it).
        'notice_templates' => array_filter([
            'profile_approved' => env('MSG91_TPL_PROFILE_APPROVED'),
            'profile_rejected' => env('MSG91_TPL_PROFILE_REJECTED'),
            'interest_received' => env('MSG91_TPL_INTEREST_RECEIVED'),
            'interest_accepted' => env('MSG91_TPL_INTEREST_ACCEPTED'),
            'verification_approved' => env('MSG91_TPL_VERIFICATION_APPROVED'),
            'verification_rejected' => env('MSG91_TPL_VERIFICATION_REJECTED'),
            'payment_success' => env('MSG91_TPL_PAYMENT_SUCCESS'),
            'plan_expiring' => env('MSG91_TPL_PLAN_EXPIRING'),
            'plan_expired' => env('MSG91_TPL_PLAN_EXPIRED'),
            'safety_warning' => env('MSG91_TPL_SAFETY_WARNING'),
            // A10 P0 page to on-call admins: "Urgent Oppam safety case {#var#} — open the admin panel." (P6.2)
            'safety_p0_case' => env('MSG91_TPL_SAFETY_P0_CASE'),
            // §11A B.8 staff invitation: "{#var#} invited you to join their bureau on Oppam Matrimony: {#var#} (valid 72 hours)" (P7.5)
            'staff_invite' => env('MSG91_TPL_STAFF_INVITE'),
        ]),
    ],

    // Payments (PRD §4, M10). The `payments.razorpay_live` feature flag picks live or test keys.
    'razorpay' => [
        'live' => [
            'key_id' => env('RAZORPAY_KEY_ID', ''),
            'key_secret' => env('RAZORPAY_KEY_SECRET', ''),
            'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET', ''),
        ],
        'test' => [
            'key_id' => env('RAZORPAY_TEST_KEY_ID', ''),
            'key_secret' => env('RAZORPAY_TEST_KEY_SECRET', ''),
            'webhook_secret' => env('RAZORPAY_TEST_WEBHOOK_SECRET', ''),
        ],
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
