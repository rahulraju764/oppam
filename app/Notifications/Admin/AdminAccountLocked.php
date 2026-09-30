<?php

declare(strict_types=1);

namespace App\Notifications\Admin;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the other super admins that an admin account was locked after too many wrong 2FA codes
 * (PRD A01 "lock + alert super admins"). Carries the email only — never the code tried.
 */
final class AdminAccountLocked extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $lockedEmail)
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
            ->subject(__('Admin account locked: :email', ['email' => $this->lockedEmail]))
            ->line(__('The admin account :email was locked after too many wrong two-factor codes.', ['email' => $this->lockedEmail]))
            ->line(__('If this wasn’t them, treat it as an attack on that account. You can unlock it from Admin users once you have spoken to them.'));
    }
}
