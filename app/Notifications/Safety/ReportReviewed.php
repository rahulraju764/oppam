<?php

declare(strict_types=1);

namespace App\Notifications\Safety;

use App\Data\Notifications\NotificationContentData;
use App\Enums\NotificationEvent;
use App\Notifications\Support\MemberNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To a member who reported someone, once the A10 case is decided (PRD A10 "reporter gets 'reviewed
 * and closed' only"): never says what was decided or names the member reported.
 */
final class ReportReviewed extends MemberNotification
{
    public function event(): NotificationEvent
    {
        return NotificationEvent::AccountStatus;
    }

    public function content(object $notifiable): NotificationContentData
    {
        return new NotificationContentData(
            title: __('Your report was reviewed'),
            body: __('Thank you for helping keep Oppam safe. Our team reviewed your report and closed it.'),
            url: route('safety'),
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $content = $this->content($notifiable);

        return $this->withPreferencesLink((new MailMessage)
            ->subject($content->title)
            ->line($content->body));
    }
}
