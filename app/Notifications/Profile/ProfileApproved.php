<?php

declare(strict_types=1);

namespace App\Notifications\Profile;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your profile is live" (A04 approve). Mail to members who gave an email; in-app + live
 * notification channels arrive with the notifications system (P3.2).
 */
final class ProfileApproved extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $profileCode)
    {
        $this->onQueue('mail');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return filled($notifiable->email ?? null) ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Your Oppam Matrimony profile is live'))
            ->line(__('Good news — your profile :code has been reviewed and is now visible to other members.', ['code' => $this->profileCode]))
            ->line(__('Add more photos and complete your preferences to get better matches.'))
            ->action(__('View my profile'), route('member.profile.me'));
    }
}
