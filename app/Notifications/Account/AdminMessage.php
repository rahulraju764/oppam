<?php

declare(strict_types=1);

namespace App\Notifications\Account;

use App\Data\Notifications\NotificationContentData;
use App\Enums\NotificationEvent;
use App\Notifications\Support\MemberNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

/**
 * A message from the Oppam team to selected members (A03 bulk "send notification"), in-app, live
 * and by email (M08 `account_status`: an account message the member can't switch off). Subject and
 * body are plain text typed by the admin — always escaped where shown.
 */
final class AdminMessage extends MemberNotification
{
    public function __construct(public readonly string $subject, public readonly string $body) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::AccountStatus;
    }

    public function content(object $notifiable): NotificationContentData
    {
        return new NotificationContentData(
            title: Str::limit($this->subject, 120),
            body: Str::limit($this->body, 300),
            url: route('member.notifications'),
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withPreferencesLink((new MailMessage)
            ->subject($this->subject)
            ->line($this->body));
    }
}
