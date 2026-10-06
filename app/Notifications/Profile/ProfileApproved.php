<?php

declare(strict_types=1);

namespace App\Notifications\Profile;

use App\Data\Notifications\NotificationContentData;
use App\Enums\NotificationEvent;
use App\Notifications\Support\MemberNotification;
use Illuminate\Notifications\Messages\MailMessage;

/** A04: the member's new profile passed review and is live (M08 `profile_approved`, mandatory). */
final class ProfileApproved extends MemberNotification
{
    public function __construct(public readonly string $profileCode) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::ProfileApproved;
    }

    public function content(object $notifiable): NotificationContentData
    {
        return new NotificationContentData(
            title: __('Your profile is live'),
            body: __('Your profile :code has been reviewed and is now visible to other members.', ['code' => $this->profileCode]),
            url: route('member.profile.me'),
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withPreferencesLink((new MailMessage)
            ->subject(__('Your Oppam Matrimony profile is live'))
            ->line(__('Good news — your profile :code has been reviewed and is now visible to other members.', ['code' => $this->profileCode]))
            ->line(__('Add more photos and complete your preferences to get better matches.'))
            ->action(__('View my profile'), route('member.profile.me')));
    }

    /** @return list<string> */
    public function smsVariables(object $notifiable): array
    {
        return [$this->profileCode];
    }
}
