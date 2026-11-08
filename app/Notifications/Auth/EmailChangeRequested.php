<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your account email is being changed" (M14, P8.2b) — to the CURRENT verified address when the
 * member asks to move to another one. Never names the new address.
 */
final class EmailChangeRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
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
            ->subject(__('Your Oppam Matrimony email is being changed'))
            ->line(__('Someone signed in to your account asked to use a different email address. This address stays in use until the new one is confirmed.'))
            ->line(__('If this wasn’t you, reset your password now and contact our support team.'))
            ->action(__('Reset password'), route('password.forgot'));
    }
}
