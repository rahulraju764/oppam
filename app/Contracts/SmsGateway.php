<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\OtpPurpose;
use App\Exceptions\Sms\SmsDeliveryFailed;
use App\ValueObjects\PhoneNumber;

/**
 * Outbound SMS (PRD §4: MSG91 with DLT-registered templates, behind this interface). Bound from
 * config('oppam.sms.driver'): msg91 in production, log locally, a fake in tests.
 */
interface SmsGateway
{
    /**
     * Send a one-time code. Implementations must never log the code outside local development.
     *
     * @throws SmsDeliveryFailed when the provider refuses or can't be reached
     */
    public function sendOtp(PhoneNumber $to, string $code, OtpPurpose $purpose): void;

    /**
     * "Someone tried to register with your number — you already have an account, log in
     * instead" (M01: registration never says a number is taken on screen — decisions 2026-09-29).
     *
     * @throws SmsDeliveryFailed
     */
    public function sendAccountExistsNotice(PhoneNumber $to): void;

    /**
     * A member notification by SMS (PRD M08: interest received / accepted, profile approved…) with
     * the DLT template registered for $templateKey (the notification event, e.g. "profile_approved")
     * and its variables in template order. Never logs message content beyond the masked number.
     *
     * $templateId, when given, is the DLT template id set in the A14 template manager (P8.3a); it
     * wins over the configured one. Returns the provider's request id (for the delivery log and
     * its delivery reports), or null when the provider gives none.
     *
     * @param  list<string>  $variables
     *
     * @throws SmsDeliveryFailed when no DLT template is registered for the key, or the provider refuses
     */
    public function sendNotification(PhoneNumber $to, string $templateKey, array $variables, ?string $templateId = null): ?string;
}
