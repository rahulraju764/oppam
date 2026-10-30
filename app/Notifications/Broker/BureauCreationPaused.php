<?php

declare(strict_types=1);

namespace App\Notifications\Broker;

use App\Data\Notifications\NotificationContentData;
use App\Enums\NotificationEvent;
use App\Notifications\Support\MemberNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * R-A07-10: A07 paused — or allowed again — adding and importing profiles at the bureau (M08
 * `account_status`, mandatory). Sent to the Owner. Never carries the admin's reason; the bureau
 * contacts support.
 */
final class BureauCreationPaused extends MemberNotification
{
    public function __construct(public readonly bool $paused) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::AccountStatus;
    }

    public function content(object $notifiable): NotificationContentData
    {
        return $this->paused
            ? new NotificationContentData(
                title: __('Adding profiles is paused for your bureau'),
                body: __('Our team has paused adding and importing profiles while we review client complaints. Everything else keeps working. Contact our support team if you have questions.'),
                url: route('broker.dashboard'),
            )
            : new NotificationContentData(
                title: __('You can add profiles again'),
                body: __('Our team has finished the review. Your bureau can add and import profiles again.'),
                url: route('broker.dashboard'),
            );
    }

    /** Bureau logins have no member notification settings page: no preferences link. */
    public function toMail(object $notifiable): MailMessage
    {
        $content = $this->content($notifiable);

        return (new MailMessage)->subject($content->title.' – '.config('oppam.site.name'))
            ->line($content->body)
            ->action(__('Open'), $content->url);
    }
}
