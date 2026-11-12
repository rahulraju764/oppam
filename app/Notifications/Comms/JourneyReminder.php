<?php

declare(strict_types=1);

namespace App\Notifications\Comms;

use App\Data\Notifications\NotificationContentData;
use App\Enums\CommsChannel;
use App\Enums\JourneyKey;
use App\Enums\NotificationEvent;
use App\Notifications\Support\InAppChannel;
use App\Notifications\Support\MemberNotification;
use App\Notifications\Support\SmsChannel;

/**
 * An A14 journey message (P8.3b2), on the journey's ONE configured channel ($onlyChannel) and only
 * if the member's preferences for the journey's event allow it there (NotificationRouter). The
 * copy here is the default; an admin's A14 template for the event replaces it (TemplateCopy).
 * Carries links and a count only — no other member's name, code or photo (the pending-interest
 * reminder says "an interest", and the block check still runs at send: actorProfileId).
 */
final class JourneyReminder extends MemberNotification
{
    public function __construct(
        public readonly JourneyKey $journey,
        CommsChannel $channel,
        public readonly int $count = 0,
        public readonly ?string $actorId = null,
    ) {
        $this->onlyChannel = match ($channel) {
            CommsChannel::Email => 'mail',
            CommsChannel::Sms => SmsChannel::class,
            CommsChannel::InApp => InAppChannel::class,
        };
    }

    public function event(): NotificationEvent
    {
        return $this->journey->event();
    }

    public function actorProfileId(): ?string
    {
        return $this->actorId;
    }

    public function content(object $notifiable): NotificationContentData
    {
        return match ($this->journey) {
            JourneyKey::PendingInterest => new NotificationContentData(
                title: __('An interest is waiting for your reply'),
                body: __('Someone is waiting to hear from you. Accept or decline it — either way, they’ll know where they stand.'),
                url: route('member.interests'),
            ),
            JourneyKey::WizardIncomplete => new NotificationContentData(
                title: __('Finish your profile to start getting matches'),
                body: __('Your profile isn’t visible to anyone yet. It takes a few minutes to complete.'),
                url: route('member.dashboard'),
            ),
            JourneyKey::InactiveMatches => new NotificationContentData(
                title: trans_choice(':count new match is waiting for you|:count new matches are waiting for you', $this->count, ['count' => $this->count]),
                body: __('We’ve picked new profiles for you since your last visit.'),
                url: route('member.matches'),
            ),
            JourneyKey::WinBack => new NotificationContentData(
                title: __('We miss you on :site', ['site' => config('oppam.site.name')]),
                body: __('Your plan has ended. See the plans to keep messaging and viewing contacts.'),
                url: route('plans'),
            ),
        };
    }
}
