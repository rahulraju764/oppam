<?php

declare(strict_types=1);

namespace App\Services\Comms;

use App\Enums\CommsChannel;
use App\Enums\OutboundStatus;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Notifications\Comms\CampaignMessage;
use App\Notifications\Support\MemberNotification;
use App\ValueObjects\PhoneNumber;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * A14 delivery log writer (P8.3a): one OutboundMessage per outbound email / SMS, written by the
 * channel that sent it (TemplatedMailChannel, SmsChannel) or the Action that texted directly
 * (SendOtp, staff invitations, on-call pages). Never the message text; recipients masked.
 * Only a MemberNotification is kept (encrypted) for a resend — member notifications never carry a
 * link token or code; auth / invitation mails and OTPs are logged without one and are never
 * resendable (same rule as P8.2b: tokens must not sit in stored rows).
 * Logging must never break a send: every write is rescued.
 */
final class DeliveryRecorder
{
    public function notification(object $notifiable, Notification $notification, CommsChannel $channel, ?string $providerId, ?Throwable $failure = null): ?OutboundMessage
    {
        return rescue(function () use ($notifiable, $notification, $channel, $providerId, $failure): OutboundMessage {
            $member = $notification instanceof MemberNotification ? $notification : null;
            // A resend (ResendFailedMessage) points back at the row it came from; the stored copy is the
            // plain notification, so a later resend of this row starts clean.
            $resentFromId = $member?->resentFromId;
            $payload = null;
            // A campaign message is never resent from the log (decisions 2026-11-08 P8.3b row): no copy is kept.
            $campaign = $notification instanceof CampaignMessage ? $notification->campaign : null;
            if ($member !== null && $campaign === null && $notifiable instanceof User) {
                $copy = clone $member;
                $copy->onlyChannel = null;
                $copy->resentFromId = null;
                $payload = serialize($copy);
            }

            $row = new OutboundMessage;
            $row->forceFill([
                'user_id' => $notifiable instanceof User ? $notifiable->id : null,
                'channel' => $channel,
                'recipient_masked' => $this->maskedRecipient($notifiable, $channel),
                'event' => $member?->event()->value ?? Str::snake(class_basename($notification)),
                'notification_type' => $notification::class,
                'status' => $failure === null ? OutboundStatus::Sent : OutboundStatus::Failed,
                'provider_id' => $providerId !== null ? mb_substr($providerId, 0, 100) : null,
                'error_message' => $failure !== null ? mb_substr($failure::class.': '.$this->safeMessage($failure), 0, 255) : null,
                'failed_at' => $failure !== null ? now() : null,
                'payload' => $payload,
                'resent_from_id' => $resentFromId,
                'campaign_id' => $campaign?->id,
                'subject' => $campaign !== null ? mb_substr($campaign->code.' · '.$campaign->name, 0, 255) : null,
                'sent_at' => now(),
            ])->save();

            return $row;
        }, null, report: true);
    }

    /** A text sent straight through SmsGateway (OTP, staff invitation, on-call page): never resendable. */
    public function directSms(?User $user, PhoneNumber $to, string $event, ?string $providerId, ?Throwable $failure = null): void
    {
        rescue(function () use ($user, $to, $event, $providerId, $failure): void {
            (new OutboundMessage)->forceFill([
                'user_id' => $user?->id,
                'channel' => CommsChannel::Sms,
                'recipient_masked' => $to->masked(),
                'event' => $event,
                'status' => $failure === null ? OutboundStatus::Sent : OutboundStatus::Failed,
                'provider_id' => $providerId,
                'error_message' => $failure !== null ? mb_substr($failure::class, 0, 255) : null,
                'failed_at' => $failure !== null ? now() : null,
                'sent_at' => now(),
            ])->save();
        }, null, report: true);
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }

    private function maskedRecipient(object $notifiable, CommsChannel $channel): string
    {
        $route = match (true) {
            $notifiable instanceof AnonymousNotifiable => $notifiable->routes[$channel === CommsChannel::Email ? 'mail' : 'sms'] ?? null,
            $channel === CommsChannel::Email && method_exists($notifiable, 'routeNotificationFor') => $notifiable->routeNotificationFor('mail'),
            default => $notifiable->phone ?? null,
        };
        $route = is_array($route) ? (string) array_key_first($route) : (string) $route;

        if ($channel === CommsChannel::Email) {
            return $route !== '' ? self::maskEmail($route) : '—';
        }

        $phone = PhoneNumber::tryParse($route);

        return $phone?->masked() ?? '—';
    }

    /** Provider errors can echo the address; keep only the first line, without anything that looks like one. */
    private function safeMessage(Throwable $e): string
    {
        $line = strtok($e->getMessage(), "\n") ?: '';

        return (string) preg_replace(['/[^\s@]+@[^\s@]+/', '/\+?\d[\d\s-]{7,}\d/'], ['[email]', '[number]'], $line);
    }
}
