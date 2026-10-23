<?php

declare(strict_types=1);

namespace App\Actions\Broker;

use App\Actions\Auth\SendOtp;
use App\Actions\Auth\VerifyOtp;
use App\Domain\Broker\TrustedDevices;
use App\Enums\OtpPurpose;
use App\Exceptions\Auth\OtpInvalid;
use App\Exceptions\Auth\OtpThrottled;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\ValueObjects\PhoneNumber;
use Illuminate\Http\Request;

/**
 * "Owner and Manager must pass an OTP challenge on each new device" (PRD §8.1, B.18): text a
 * BROKER_DEVICE code to the login's own mobile (the usual OTP limits apply), and on the right code
 * trust THIS device (its cookie hash). The code must have been issued to this login. Audited.
 */
final class VerifyBrokerDevice
{
    public function __construct(
        private readonly SendOtp $sendOtp,
        private readonly VerifyOtp $verifyOtp,
        private readonly TrustedDevices $devices,
        private readonly AuditLogger $audit,
    ) {}

    /** @throws OtpThrottled */
    public function send(User $user, Request $request): void
    {
        $this->sendOtp->handle(PhoneNumber::fromE164($user->phone), OtpPurpose::BrokerDevice, (string) $request->ip(), $user);
    }

    /** @throws OtpInvalid */
    public function verify(User $user, string $code, Request $request): void
    {
        $device = $this->devices->hash($request) ?? throw OtpInvalid::wrongOrExpired();
        $challenge = $this->verifyOtp->handle(PhoneNumber::fromE164($user->phone), OtpPurpose::BrokerDevice, $code);

        if ($challenge->user_id !== $user->id) {
            throw OtpInvalid::wrongOrExpired();
        }

        $this->devices->trust($user, $device);
        $this->audit->record('broker.device_verified', $user, actor: $user);   // the row carries the IP and browser
    }
}
