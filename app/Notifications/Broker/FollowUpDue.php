<?php

declare(strict_types=1);

namespace App\Notifications\Broker;

use App\Data\Notifications\NotificationContentData;
use App\Enums\NotificationEvent;
use App\Notifications\Support\MemberNotification;

/**
 * §11A B.15 "FollowUpDue" (08:30 IST, SendFollowUpReminders): one in-app notification + live toast
 * per bureau login with the number of client follow-ups due today or overdue — no client names.
 */
final class FollowUpDue extends MemberNotification
{
    public function __construct(public readonly int $count) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::FollowUpDue;
    }

    public function content(object $notifiable): NotificationContentData
    {
        return new NotificationContentData(
            title: trans_choice('{1} 1 client follow-up is due|[2,*] :count client follow-ups are due', $this->count, ['count' => $this->count]),
            body: __('Open your dashboard to see who to call today.'),
            url: route('broker.dashboard'),
        );
    }
}
