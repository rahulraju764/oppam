<?php

declare(strict_types=1);

namespace App\Notifications\Broker;

use App\Data\Notifications\NotificationContentData;
use App\Enums\NotificationEvent;
use App\Notifications\Support\MemberNotification;

/**
 * §11A B.15 "ManagedProfileModerated" to the people of the bureau who may read the outcome — the
 * Owner, Managers, the creator and the assignee, each only while the profile is inside their scope
 * (in-app + live toast). Rejections carry the moderator's reason (B.6 "reason shown to the bureau").
 */
final class ManagedProfileReviewed extends MemberNotification
{
    public function __construct(
        public readonly string $profileCode,
        public readonly bool $approved,
        public readonly ?string $reason = null,
    ) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::ManagedProfileReviewed;
    }

    public function content(object $notifiable): NotificationContentData
    {
        $url = route('broker.profiles.show', ['code' => $this->profileCode]);

        return $this->approved
            ? new NotificationContentData(
                title: __('Profile :code is live', ['code' => $this->profileCode]),
                body: __('Our team approved the profile. It now appears in search.'),
                url: $url,
            )
            : new NotificationContentData(
                title: __('Profile :code needs changes', ['code' => $this->profileCode]),
                body: $this->reason !== null
                    ? __('Reason: :reason Edit the profile and submit it again.', ['reason' => $this->reason])
                    : __('Edit the profile and submit it again.'),
                url: $url,
            );
    }
}
