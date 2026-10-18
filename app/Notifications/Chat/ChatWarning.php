<?php

declare(strict_types=1);

namespace App\Notifications\Chat;

use App\Data\Notifications\NotificationContentData;
use App\Enums\NotificationEvent;
use App\Notifications\Support\MemberNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A moderator's warning about a chat message (PRD A13 "warn sender"): in-app, live and by email
 * (M08 `account_status`, which the member can't switch off). Fixed wording — it never quotes the
 * message or names the member who reported it.
 */
final class ChatWarning extends MemberNotification
{
    public function event(): NotificationEvent
    {
        return NotificationEvent::AccountStatus;
    }

    public function content(object $notifiable): NotificationContentData
    {
        return new NotificationContentData(
            title: __('A message you sent goes against our guidelines'),
            body: __('Please keep conversations respectful, never ask for money, and share contact details only when you are both comfortable. Repeated problems can lead to your account being suspended.'),
            url: route('member.messages'),
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $content = $this->content($notifiable);

        return $this->withPreferencesLink((new MailMessage)
            ->subject($content->title)
            ->line($content->body)
            ->action(__('Open messages'), route('member.messages')));
    }
}
