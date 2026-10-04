<?php

declare(strict_types=1);

namespace App\Notifications\Account;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your password was reset by our team" (A03 quick action). Carries no password and no link
 * token — the member sets a new one through "Forgot password" (a code to their mobile).
 * Verified email only (the Action checks).
 */
final class PasswordResetByAdmin extends Notification implements ShouldQueue
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
            ->subject(__('Your Oppam Matrimony password was reset'))
            ->line(__('Our support team reset your password and signed your account out on all devices.'))
            ->line(__('To sign in again, choose “Forgot password” — we will send a code to your mobile number.'))
            ->action(__('Set a new password'), route('password.forgot'));
    }
}
