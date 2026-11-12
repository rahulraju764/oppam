<?php

declare(strict_types=1);

namespace App\Notifications\Account;

use App\Data\Notifications\NotificationContentData;
use App\Enums\NotificationEvent;
use App\Notifications\Support\MemberNotification;

/**
 * "We hid your profile while you were away" (A15 `profile.auto_hide_inactive_days`, P8.4): sent by
 * HideInactiveProfiles. M08 `account_status` (mandatory — the member must learn why they left
 * search). Unhiding is one switch in /settings → Hide / delete.
 */
final class ProfileHiddenForInactivity extends MemberNotification
{
    public function __construct(public readonly int $days) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::AccountStatus;
    }

    public function content(object $notifiable): NotificationContentData
    {
        return new NotificationContentData(
            title: __('We’ve hidden your profile while you’re away'),
            body: __('You haven’t visited in :days days, so your profile is out of search for now. Show it again any time in Settings.', ['days' => $this->days]),
            url: route('member.settings', ['tab' => 'manage']),
        );
    }
}
