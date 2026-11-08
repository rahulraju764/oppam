<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Reset your password" (PRD §8.2 method 3, email link; P8.2b) — only ever to a verified account address. */
final class PasswordResetLink extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $url,
        public readonly int $minutes,
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
            ->subject(__('Reset your Oppam Matrimony password'))
            ->line(__('We got a request to reset the password of the account that uses this email address.'))
            ->action(__('Choose a new password'), $this->url)
            ->line(__('The link works once and expires in :minutes minutes. Resetting signs you out on every other device.', ['minutes' => $this->minutes]))
            ->line(__('If you didn’t ask for this, ignore this email — your password stays the same.'));
    }
}
