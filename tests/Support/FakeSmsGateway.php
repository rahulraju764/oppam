<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\SmsGateway;
use App\Enums\OtpPurpose;
use App\Exceptions\Sms\SmsDeliveryFailed;
use App\ValueObjects\PhoneNumber;

/** Captures OTP SMS in memory (oppam-testing: fake external systems). */
final class FakeSmsGateway implements SmsGateway
{
    /** @var list<array{to: string, code: string, purpose: OtpPurpose}> */
    public array $sent = [];

    public bool $failing = false;

    public function sendOtp(PhoneNumber $to, string $code, OtpPurpose $purpose): void
    {
        if ($this->failing) {
            throw SmsDeliveryFailed::because('fake gateway set to fail');
        }

        $this->sent[] = ['to' => $to->e164(), 'code' => $code, 'purpose' => $purpose];
    }

    /** @var list<string> E.164 numbers that got the "you already have an account" SMS */
    public array $accountExistsNotices = [];

    public function sendAccountExistsNotice(PhoneNumber $to): void
    {
        if ($this->failing) {
            throw SmsDeliveryFailed::because('fake gateway set to fail');
        }

        $this->accountExistsNotices[] = $to->e164();
    }

    /** The last code texted to $phone (E.164), optionally for one purpose. */
    public function lastCodeFor(string $phone, ?OtpPurpose $purpose = null): ?string
    {
        foreach (array_reverse($this->sent) as $sms) {
            if ($sms['to'] === $phone && ($purpose === null || $sms['purpose'] === $purpose)) {
                return $sms['code'];
            }
        }

        return null;
    }

    public function countFor(string $phone): int
    {
        return count(array_filter($this->sent, fn (array $sms): bool => $sms['to'] === $phone));
    }
}
