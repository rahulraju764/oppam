<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Confirm your email address" (M14, P8.2b) — sent to the NEW address only (an on-demand mail
 * route, never the account's current address). Carries the single-use link; nothing else about
 * the account.
 */
final class VerifyEmailAddress extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $url,
        public readonly int $hours,
    ) {
        $this->onQueue('mail');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Confirm your email address – :site', ['site' => config('oppam.site.name')]))
            ->line(__('Someone asked to use this email address for an Oppam Matrimony account. If that was you, confirm it below.'))
            ->action(__('Confirm my email address'), $this->url)
            ->line(__('The link works once and expires in :hours hours. If you didn’t ask for this, ignore this email — nothing changes.', ['hours' => $this->hours]));
    }
}
