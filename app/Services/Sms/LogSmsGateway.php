<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Contracts\SmsGateway;
use App\Enums\OtpPurpose;
use App\ValueObjects\PhoneNumber;
use Illuminate\Log\LogManager;

/**
 * Local development only: writes the SMS (code included) to storage/logs/sms.log so you can
 * finish a sign-in without a real SMS. AppServiceProvider refuses to bind it in production.
 */
final class LogSmsGateway implements SmsGateway
{
    public function __construct(private readonly LogManager $log) {}

    public function sendOtp(PhoneNumber $to, string $code, OtpPurpose $purpose): void
    {
        $this->log->channel('sms')->info("[{$purpose->value}] OTP for {$to->masked()}: {$code}");
    }

    /** @param  list<string>  $variables */
    public function sendNotification(PhoneNumber $to, string $templateKey, array $variables, ?string $templateId = null): string
    {
        $this->log->channel('sms')->info("[NOTICE {$templateKey}".($templateId !== null ? " DLT {$templateId}" : '')."] for {$to->masked()}");

        return 'log-'.bin2hex(random_bytes(8));
    }

    public function sendAccountExistsNotice(PhoneNumber $to): void
    {
        $this->log->channel('sms')->info("[ACCOUNT_EXISTS] notice for {$to->masked()}: you already have an Oppam account — log in instead");
    }
}
