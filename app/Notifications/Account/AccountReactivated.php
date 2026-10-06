<?php

declare(strict_types=1);

namespace App\Notifications\Account;

use App\Data\Notifications\NotificationContentData;
use App\Enums\NotificationEvent;
use App\Notifications\Support\MemberNotification;
use Illuminate\Notifications\Messages\MailMessage;

/** "Your account is active again" (A03), after an admin lifts a suspension (M08 `account_status`, mandatory). */
final class AccountReactivated extends MemberNotification
{
    public function event(): NotificationEvent
    {
        return NotificationEvent::AccountStatus;
    }

    public function content(object $notifiable): NotificationContentData
    {
        return new NotificationContentData(
            title: __('Your account is active again'),
            body: __('Your account has been reactivated. Welcome back!'),
            url: route('member.dashboard'),
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withPreferencesLink((new MailMessage)
            ->subject(__('Your Oppam Matrimony account is active again'))
            ->line(__('Good news — your account has been reactivated. You can log in again.'))
            ->action(__('Log in'), route('login')));
    }
}
