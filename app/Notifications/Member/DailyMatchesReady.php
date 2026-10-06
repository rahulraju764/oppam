<?php

declare(strict_types=1);

namespace App\Notifications\Member;

use App\Data\Notifications\NotificationContentData;
use App\Enums\NotificationEvent;
use App\Notifications\Support\MemberNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * "Your N new matches are ready" (F06, PRD §10 M08 `daily_matches_ready`): in-app, live and by
 * email per the member's preferences. A count and a link only.
 */
final class DailyMatchesReady extends MemberNotification
{
    public function __construct(public readonly int $count) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::DailyMatchesReady;
    }

    public function content(object $notifiable): NotificationContentData
    {
        return new NotificationContentData(
            title: trans_choice('Your :count new match is ready|Your :count new matches are ready', $this->count, ['count' => $this->count]),
            body: __('Today’s hand-picked matches are available until midnight.'),
            url: route('member.matches.daily'),
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withPreferencesLink((new MailMessage)
            ->subject(trans_choice('Your :count new match is ready|Your :count new matches are ready', $this->count, ['count' => $this->count]).' – '.config('oppam.site.name'))
            ->greeting(__('Good morning!'))
            ->line(trans_choice('We picked :count new profile for you today.|We picked :count new profiles for you today.', $this->count, ['count' => $this->count]))
            ->action(__('See today’s matches'), route('member.matches.daily'))
            ->line(__('Today’s matches are available until midnight.')));
    }
}
