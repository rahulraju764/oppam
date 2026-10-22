<?php

declare(strict_types=1);

namespace App\Notifications\Billing;

use App\Data\Notifications\NotificationContentData;
use App\Enums\NotificationEvent;
use App\Notifications\Support\MemberNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The plan ended and the account is on Free limits again (PRD M10 "on expiry revert to Free
 * limits (data kept)"; M08 `plan_expired`, mandatory). Sent once, when no plan follows.
 */
final class PlanExpiredNotification extends MemberNotification
{
    public function __construct(public readonly string $planName) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::PlanExpired;
    }

    public function content(object $notifiable): NotificationContentData
    {
        return new NotificationContentData(
            title: __('Your :plan plan has ended', ['plan' => $this->planName]),
            body: __('Your account is on the Free plan now. Your profile, favourites, interests and conversations are all kept — renew any time to chat and see more again.'),
            url: route('plans'),
        );
    }

    /** @return list<string> DLT variables: plan name */
    public function smsVariables(object $notifiable): array
    {
        return [$this->planName];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $content = $this->content($notifiable);

        return (new MailMessage)
            ->subject($content->title)
            ->line($content->body)
            ->action(__('See plans'), $content->url);
    }
}
