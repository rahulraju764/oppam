<?php

declare(strict_types=1);

namespace App\Notifications\Broker;

use App\Data\Notifications\NotificationContentData;
use App\Enums\BrokerStatus;
use App\Enums\NotificationEvent;
use App\Notifications\Support\MemberNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * §11A B.15 "suspension" (email + in-app, M08 `account_status`, mandatory): A07 suspended,
 * deactivated or reactivated the bureau. Sent to the Owner and — for a suspension or deactivation —
 * every staff login. Never carries the admin's internal reason; the bureau contacts support.
 */
final class BureauStatusChanged extends MemberNotification
{
    public function __construct(public readonly BrokerStatus $status) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::AccountStatus;
    }

    public function content(object $notifiable): NotificationContentData
    {
        return match ($this->status) {
            BrokerStatus::Active => new NotificationContentData(
                title: __('Your bureau is active again'),
                body: __('Our team has lifted the suspension. You and your staff can sign in again.'),
                url: route('broker.dashboard'),
            ),
            BrokerStatus::Deactivated => new NotificationContentData(
                title: __('Your bureau has been closed'),
                body: __('Our team has deactivated this bureau. Contact our support team if you have questions.'),
                url: route('login'),
            ),
            default => new NotificationContentData(
                title: __('Your bureau has been suspended'),
                body: __('Our team has suspended this bureau while we review it. Contact our support team if you have questions.'),
                url: route('login'),
            ),
        };
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
