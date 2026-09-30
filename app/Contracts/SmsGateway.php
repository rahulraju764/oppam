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
}
