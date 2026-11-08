<?php

declare(strict_types=1);

namespace App\Notifications\Account;

use App\Data\Notifications\NotificationContentData;
use App\Enums\AccountSecurityChange;
use App\Enums\NotificationEvent;
use App\Notifications\Support\MemberNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * "Your mobile / email / password changed" (M14 Account tab, P8.2b). Mandatory (R-M08-3): in-app,
 * plus email to a verified address. Says what changed and when to worry; never a number, an
 * address, a code or a link token.
 */
final class AccountSecurityChanged extends MemberNotification
{
    public function __construct(public readonly AccountSecurityChange $change) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::AccountSecurity;
    }

    public function content(object $notifiable): NotificationContentData
    {
        return new NotificationContentData(
            title: $this->change->title(),
            body: $this->change->body(),
            url: route('member.settings', ['tab' => 'account']),
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withPreferencesLink(
            (new MailMessage)->subject($this->change->title().' – '.config('oppam.site.name'))
                ->line($this->change->body())
                ->line(__('If this wasn’t you, reset your password now and contact our support team.'))
                ->action(__('Open your account settings'), route('member.settings', ['tab' => 'account']))
        );
    }
}
