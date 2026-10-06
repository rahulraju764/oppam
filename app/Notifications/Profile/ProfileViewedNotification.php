<?php

declare(strict_types=1);

namespace App\Notifications\Profile;

use App\Data\Notifications\NotificationContentData;
use App\Domain\Profile\ProfileCards;
use App\Enums\Entitlement;
use App\Enums\NotificationEvent;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\Support\MemberNotification;
use App\Services\Entitlements\EntitlementService;

/**
 * "Someone viewed your profile" (PRD M08 `profile_viewed`, M15): once per viewer per IST day (the
 * view recorder only sends on a viewer's first visit of the day — R-M08-2). The viewer is named,
 * with the photo the recipient may see, only for plans with see_who_viewed_me (Gold+); everyone
 * else is told that someone looked, with a link to Visitors. Never about a blocked pair (base class).
 */
final class ProfileViewedNotification extends MemberNotification
{
    public function __construct(public readonly string $viewerProfileId) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::ProfileViewed;
    }

    public function actorProfileId(): string
    {
        return $this->viewerProfileId;
    }

    public function content(object $notifiable): NotificationContentData
    {
        $viewer = Profile::query()->find($this->viewerProfileId);
        $recipient = $notifiable instanceof User ? Profile::query()->where('user_id', $notifiable->id)->first() : null;
        $named = $viewer !== null && $recipient !== null
            && app(EntitlementService::class)->allows($recipient, Entitlement::SeeWhoViewedMe);

        if (! $named) {
            return new NotificationContentData(
                title: __('Someone viewed your profile'),
                body: __('Upgrade to Gold to see who is interested in you.'),
                url: route('member.visitors'),
            );
        }

        $card = app(ProfileCards::class)->forViewer($viewer, $notifiable);

        return new NotificationContentData(
            title: __(':name viewed your profile', ['name' => $card->name]),
            body: __('Take a look at their profile.'),
            url: route('member.profile.show', ['profile' => $viewer->code]),
            actorCode: $viewer->code,
            actorName: $card->name,
            actorPhotoThumb: $card->photoUrl,
        );
    }
}
