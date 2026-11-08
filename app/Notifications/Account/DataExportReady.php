<?php

declare(strict_types=1);

namespace App\Notifications\Account;

use App\Data\Notifications\NotificationContentData;
use App\Enums\NotificationEvent;
use App\Notifications\Support\MemberNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * "Your copy of your data is ready" (M14, P8.2c). Points at /settings → Hide / delete, where the
 * signed-in member downloads it. It carries no link token or file path — the queued notification
 * and the email hold nothing that opens the file by itself (same rule as P8.2b reset links).
 */
final class DataExportReady extends MemberNotification
{
    public function event(): NotificationEvent
    {
        return NotificationEvent::AccountStatus;
    }

    public function content(object $notifiable): NotificationContentData
    {
        return new NotificationContentData(
            title: __('Your data is ready to download'),
            body: __('The copy of your data you asked for is ready. Download it from your settings within 24 hours.'),
            url: route('member.settings', ['tab' => 'manage']),
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withPreferencesLink(
            (new MailMessage)
                ->subject(__('Your Oppam data is ready to download'))
                ->line(__('The copy of your data you asked for is ready.'))
                ->line(__('Sign in and download it from your settings within 24 hours. After that it is deleted.'))
                ->action(__('Open my settings'), route('member.settings', ['tab' => 'manage']))
        );
    }
}
