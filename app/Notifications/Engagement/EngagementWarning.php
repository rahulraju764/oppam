<?php

declare(strict_types=1);

namespace App\Notifications\Engagement;

use App\Data\Notifications\NotificationContentData;
use App\Enums\NotificationEvent;
use App\Notifications\Support\MemberNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A moderator's warning after an A13 abuse signal (PRD A13 one-click "warn"): in-app, live and by
 * email (M08 `account_status`, which the member can't switch off). Fixed wording — it never says what
 * was detected in detail or who was contacted.
 */
final class EngagementWarning extends MemberNotification
{
    public function event(): NotificationEvent
    {
        return NotificationEvent::AccountStatus;
    }

    public function content(object $notifiable): NotificationContentData
    {
        return new NotificationContentData(
            title: __('Please slow down when contacting members'),
            body: __('We noticed unusual activity from your account — many likes, interests or messages in a short time, or messages that get no reply. Please reach out only to members you are genuinely interested in. Repeated problems can lead to your account being paused or suspended.'),
            url: route('member.dashboard'),
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $content = $this->content($notifiable);

        return $this->withPreferencesLink((new MailMessage)
            ->subject($content->title)
            ->line($content->body)
            ->action(__('Open Oppam'), route('member.dashboard')));
    }
}
