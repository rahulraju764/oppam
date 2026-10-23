<?php

declare(strict_types=1);

namespace App\Notifications\Safety;

use App\Data\Notifications\NotificationContentData;
use App\Enums\NotificationEvent;
use App\Notifications\Support\MemberNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * An A10 warning after reports (PRD A10 outcome "warning (live `safety_warning` notification)",
 * M08 `safety_warning` — in-app, email and SMS, can't be switched off). Fixed wording: it never
 * names who reported the member or quotes what was reported.
 */
final class SafetyWarning extends MemberNotification
{
    public function event(): NotificationEvent
    {
        return NotificationEvent::SafetyWarning;
    }

    public function content(object $notifiable): NotificationContentData
    {
        return new NotificationContentData(
            title: __('Your account has received a warning'),
            body: __('Members reported behaviour that goes against our community guidelines. Please be respectful, never ask for money and share personal details only when you are both comfortable. Repeated problems lead to suspension.'),
            url: route('safety'),
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $content = $this->content($notifiable);

        return $this->withPreferencesLink((new MailMessage)
            ->subject($content->title)
            ->line($content->body)
            ->action(__('Read our safety guidelines'), route('safety')));
    }
}
