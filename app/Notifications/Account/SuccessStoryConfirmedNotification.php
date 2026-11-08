<?php

declare(strict_types=1);

namespace App\Notifications\Account;

use App\Data\Notifications\NotificationContentData;
use App\Enums\NotificationEvent;
use App\Notifications\Support\MemberNotification;

/**
 * The partner answered the submitter's M16 story (P8.2c): confirmed (it goes on to A08 review) or
 * declined (it is withdrawn). In-app only — first name only, no story text.
 */
final class SuccessStoryConfirmedNotification extends MemberNotification
{
    public function __construct(
        public readonly string $partnerFirstName,
        public readonly bool $confirmed,
    ) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::AccountStatus;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return array_values(array_filter(parent::via($notifiable), fn (string $channel): bool => $channel !== 'mail'));
    }

    public function content(object $notifiable): NotificationContentData
    {
        return $this->confirmed
            ? new NotificationContentData(
                title: __('Your wedding story is confirmed'),
                body: __(':name agreed to share your story. Our team will review it before it appears on Oppam.', ['name' => $this->partnerFirstName]),
                url: route('member.settings', ['tab' => 'manage']),
            )
            : new NotificationContentData(
                title: __('Your wedding story won’t be shared'),
                body: __(':name chose not to share the story, so it has been withdrawn.', ['name' => $this->partnerFirstName]),
                url: route('member.settings', ['tab' => 'manage']),
            );
    }
}
