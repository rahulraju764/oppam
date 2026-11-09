<?php

declare(strict_types=1);

namespace App\Notifications\Support;

use App\Contracts\SmsGateway;
use App\Enums\CommsChannel;
use App\Models\User;
use App\Services\Comms\DeliveryRecorder;
use App\ValueObjects\PhoneNumber;
use Throwable;

/**
 * Notification SMS through the SmsGateway (MSG91 DLT templates in production). Best-effort: a
 * notification SMS that can't go out (no approved template yet, provider down) is reported and
 * skipped — the in-app and email copies are unaffected. The DLT template is the one set in the A14
 * template manager when there is one (P8.3a), else the configured one; every send or failure is
 * written to the delivery log with the provider's request id (DeliveryRecorder).
 */
final class SmsChannel
{
    public function __construct(
        private readonly SmsGateway $sms,
        private readonly DeliveryRecorder $deliveries,
    ) {}

    public function send(object $notifiable, MemberNotification $notification): ?string
    {
        if (! $notifiable instanceof User || ! filled($notifiable->phone)) {
            return null;
        }

        try {
            $requestId = $this->sms->sendNotification(
                PhoneNumber::fromE164((string) $notifiable->phone),
                $notification->event()->value,
                $notification->smsVariables($notifiable),
                $notification->templatedSmsId(),
            );
        } catch (Throwable $e) {   // SmsDeliveryFailed (no approved template, provider down) or an invalid number
            report($e);
            $this->deliveries->notification($notifiable, $notification, CommsChannel::Sms, null, $e);

            return null;
        }

        $this->deliveries->notification($notifiable, $notification, CommsChannel::Sms, $requestId);

        return $requestId;
    }
}
