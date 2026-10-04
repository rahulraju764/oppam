<?php

declare(strict_types=1);

namespace App\Notifications\Member;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your N new matches are ready" (F06, PRD §10 M08 `daily_matches_ready`). Email only for now —
 * the in-app + live copies come with the notification system (P3.2). A count and a link only.
 */
final class DailyMatchesReady extends Notification implements ShouldQueue
{
    use Queueable;

    /** notification_preferences.event (PRD §10 M08). */
    public const EVENT = 'daily_matches_ready';

    public function __construct(public readonly int $count)
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
            ->subject(trans_choice('Your :count new match is ready|Your :count new matches are ready', $this->count, ['count' => $this->count]).' – '.config('oppam.site.name'))
            ->greeting(__('Good morning!'))
            ->line(trans_choice('We picked :count new profile for you today.|We picked :count new profiles for you today.', $this->count, ['count' => $this->count]))
            ->action(__('See today’s matches'), route('member.matches.daily'))
            ->line(__('Today’s matches are available until midnight.'));
    }
}
