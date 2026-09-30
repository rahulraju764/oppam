<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Models\LoginEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "New sign-in to your account" (M01 new-device alert). Security mail: always sent when it
 * applies (R-M08-3 — cannot be switched off), only to a verified email. Carries time and IP,
 * never a code or password.
 */
final class NewDeviceSignIn extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly LoginEvent $event)
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
        $when = $this->event->created_at->timezone((string) config('oppam.display_timezone'))->format('j M Y, g:i A');

        return (new MailMessage)
            ->subject(__('New sign-in to your Oppam Matrimony account'))
            ->line(__('Your account was signed in to from a new device on :when IST (IP address :ip).', [
                'when' => $when,
                'ip' => $this->event->ip_address ?? __('unknown'),
            ]))
            ->line(__('If this was you, there is nothing to do.'))
            ->line(__('If it wasn’t you, reset your password now and choose “Log out other devices”.'))
            ->action(__('Reset password'), route('password.forgot'));
    }
}
