<?php

declare(strict_types=1);

namespace App\Notifications\Admin;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * The staff invitation email (A01). The link is single-use and expires after 72 h. Sent
 * synchronously (NOT queued): a queued notification would store the one-time token in plain text
 * in the jobs / failed_jobs tables (P0.5 review).
 */
final class AdminInvitationSent extends Notification
{
    public function __construct(
        public readonly string $url,
        public readonly string $invitedBy,
        public readonly Carbon $expiresAt,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('You’ve been invited to the Oppam Matrimony admin panel'))
            ->line(__(':name has invited you to join the Oppam Matrimony admin team.', ['name' => $this->invitedBy]))
            ->action(__('Accept invitation'), $this->url)
            ->line(__('The link works once and expires on :date (IST). You will set a password and an authenticator app.', [
                'date' => $this->expiresAt->timezone(config('oppam.display_timezone'))->format('d M Y, g:i a'),
            ]))
            ->line(__('If you weren’t expecting this, ignore this email.'));
    }
}
