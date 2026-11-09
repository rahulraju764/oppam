<?php

declare(strict_types=1);

namespace App\Notifications\Support;

use App\Enums\CommsChannel;
use App\Services\Comms\DeliveryRecorder;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Mail\SentMessage;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;
use LogicException;
use Throwable;

/**
 * Laravel's mail channel with two additions (A14, P8.3a): a member notification whose event has a
 * live email template in the A14 template manager is sent with that copy (MemberNotification::
 * templatedMail) instead of its own toMail(); and every mail it sends (or fails to send) is
 * written to the delivery log with the provider's message id (DeliveryRecorder). Otherwise it
 * sends exactly as before.
 * Bound over Illuminate\Notifications\Channels\MailChannel in AppServiceProvider. The body mirrors
 * MailChannel::send (Laravel 12) — re-check it on a framework upgrade.
 */
final class TemplatedMailChannel extends MailChannel
{
    /** @return mixed the SentMessage (its id is the provider message id for the delivery log) */
    public function send($notifiable, Notification $notification)
    {
        if (! method_exists($notification, 'toMail')) {
            throw new LogicException($notification::class.' is sent by mail but has no toMail().');
        }

        $message = ($notification instanceof MemberNotification ? $notification->templatedMail($notifiable) : null)
            ?? $notification->toMail($notifiable);

        if (! $notifiable->routeNotificationFor('mail', $notification) && ! $message instanceof Mailable) {
            return null;
        }

        if ($message instanceof Mailable) {
            try {
                $sent = $message->send($this->mailer);
            } catch (Throwable $e) {
                app(DeliveryRecorder::class)->notification($notifiable, $notification, CommsChannel::Email, null, $e);
                throw $e;
            }
            app(DeliveryRecorder::class)->notification($notifiable, $notification, CommsChannel::Email,
                $sent instanceof SentMessage ? $sent->getMessageId() : null);

            return $sent;
        }

        try {
            $sent = $this->mailer->mailer($message->mailer ?? null)->send(
                $this->buildView($message),
                array_merge($message->data(), $this->additionalMessageData($notification)),
                $this->messageBuilder($notifiable, $notification, $message)
            );
        } catch (Throwable $e) {
            app(DeliveryRecorder::class)->notification($notifiable, $notification, CommsChannel::Email, null, $e);
            throw $e;
        }

        // A14 delivery log: the provider's message id (SES / Mailgun) is what bounce reports name.
        $providerId = $sent instanceof SentMessage ? $sent->getMessageId() : null;
        app(DeliveryRecorder::class)->notification($notifiable, $notification, CommsChannel::Email, $providerId);

        return $sent;
    }
}
