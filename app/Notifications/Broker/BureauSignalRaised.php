<?php

declare(strict_types=1);

namespace App\Notifications\Broker;

use App\Data\Notifications\NotificationContentData;
use App\Enums\BrokerSignal;
use App\Enums\NotificationEvent;
use App\Notifications\Support\MemberNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * §11A B.12 "Staff logging in from many devices/IPs; export spike by one staff user → flag; Owner
 * and admin notified": tells the Owner, in general words, to check the bureau's activity log. Only
 * for the signals that BrokerSignal::tellsOwner() names; never which login or what was counted.
 */
final class BureauSignalRaised extends MemberNotification
{
    public function __construct(public readonly BrokerSignal $signal) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::AccountStatus;
    }

    public function content(object $notifiable): NotificationContentData
    {
        return new NotificationContentData(
            title: __('Please check your bureau’s activity'),
            body: $this->signal === BrokerSignal::StaffDevices
                ? __('A staff login was used from many devices or networks this week. Check the activity log and change passwords if needed.')
                : __('Your bureau exported more profiles than usual. Check the activity log to make sure every export was expected.'),
            url: route('broker.activity'),
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
