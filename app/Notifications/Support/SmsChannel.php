<?php

declare(strict_types=1);

namespace App\Notifications\Support;

use App\Contracts\SmsGateway;
use App\Models\User;
use App\ValueObjects\PhoneNumber;
use Throwable;

/**
 * Notification SMS through the SmsGateway (MSG91 DLT templates in production). Best-effort: a
 * notification SMS that can't go out (no approved template yet, provider down) is reported and
 * skipped — the in-app and email copies are unaffected.
 */
final class SmsChannel
{
    public function __construct(private readonly SmsGateway $sms) {}

    public function send(object $notifiable, MemberNotification $notification): void
    {
        if (! $notifiable instanceof User || ! filled($notifiable->phone)) {
            return;
        }

        try {
            $this->sms->sendNotification(PhoneNumber::fromE164((string) $notifiable->phone), $notification->event()->value, $notification->smsVariables($notifiable));
        } catch (Throwable $e) {   // SmsDeliveryFailed (no approved template, provider down) or an invalid number
            report($e);
        }
    }
}
