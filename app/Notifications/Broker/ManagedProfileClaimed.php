<?php

declare(strict_types=1);

namespace App\Notifications\Broker;

use App\Data\Notifications\NotificationContentData;
use App\Enums\NotificationEvent;
use App\Notifications\Support\MemberNotification;

/**
 * §11A B.6 / B.15 "ProfileClaimed": the client claimed their profile — to the bureau Owner and the
 * assignee (in-app + live toast). The code only: the profile is no longer the bureau's, so the
 * link goes to the profile list, not the profile.
 */
final class ManagedProfileClaimed extends MemberNotification
{
    public function __construct(public readonly string $profileCode) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::ManagedProfileClaimed;
    }

    public function content(object $notifiable): NotificationContentData
    {
        return new NotificationContentData(
            title: __('Profile :code was claimed by the client', ['code' => $this->profileCode]),
            body: __('The client now manages their own profile. Your bureau no longer has access to it.'),
            url: route('broker.profiles.index'),
        );
    }
}
