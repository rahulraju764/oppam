<?php

declare(strict_types=1);

namespace App\Notifications\Engagement;

use App\Data\Notifications\NotificationContentData;
use App\Domain\Profile\ProfileCards;
use App\Enums\Entitlement;
use App\Enums\NotificationEvent;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\Support\MemberNotification;
use App\Services\Entitlements\EntitlementService;

/**
 * "Someone liked your profile" (PRD M08 `profile_liked`, M06): at most once per liker per IST day
 * (ToggleLike claims OncePerDay — R-M08-2), never about an un-like (R-M06-5) and never inside a
 * blocked pair (base class). The liker is named, with the photo the recipient may see, only for
 * plans with see_who_liked_me; Free members are told that someone liked them, with a link to Likes.
 */
final class ProfileLikedNotification extends MemberNotification
{
    public function __construct(public readonly string $likerProfileId) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::ProfileLiked;
    }

    public function actorProfileId(): string
    {
        return $this->likerProfileId;
    }

    public function content(object $notifiable): NotificationContentData
    {
        $liker = Profile::query()->find($this->likerProfileId);
        $recipient = $notifiable instanceof User ? Profile::query()->where('user_id', $notifiable->id)->first() : null;

        if (! $notifiable instanceof User || $liker === null || $recipient === null
            || ! app(EntitlementService::class)->allows($recipient, Entitlement::SeeWhoLikedMe)) {
            return new NotificationContentData(
                title: __('Someone liked your profile'),
                body: __('Upgrade to see who likes you.'),
                url: route('member.likes'),
            );
        }

        $card = app(ProfileCards::class)->forViewer($liker, $notifiable);

        return new NotificationContentData(
            title: __(':name liked your profile', ['name' => $card->name]),
            body: __('Like them back to make it a match.'),
            url: route('member.profile.show', ['profile' => $liker->code]),
            actorCode: $liker->code,
            actorName: $card->name,
            actorPhotoThumb: $card->photoUrl,
        );
    }
}
