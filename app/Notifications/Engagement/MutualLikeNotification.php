<?php

declare(strict_types=1);

namespace App\Notifications\Engagement;

use App\Data\Notifications\NotificationContentData;
use App\Domain\Profile\ProfileCards;
use App\Enums\NotificationEvent;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\Support\MemberNotification;

/**
 * "It's a match!" (PRD M08 `mutual_like`, M06 / F02): both members liked each other — sent to
 * both. The other member is named on every plan: each of them chose the other, so there is
 * nothing left to hide. Never inside a blocked pair (base class).
 */
final class MutualLikeNotification extends MemberNotification
{
    public function __construct(public readonly string $otherProfileId) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::MutualLike;
    }

    public function actorProfileId(): string
    {
        return $this->otherProfileId;
    }

    public function content(object $notifiable): NotificationContentData
    {
        $other = Profile::query()->find($this->otherProfileId);

        if ($other === null) {
            return new NotificationContentData(
                title: __('It’s a match!'),
                body: __('You and another member like each other.'),
                url: route('member.likes', ['tab' => 'mutual']),
            );
        }

        $card = app(ProfileCards::class)->forViewer($other, $notifiable instanceof User ? $notifiable : null);

        return new NotificationContentData(
            title: __('It’s a match! You and :name like each other', ['name' => $card->name]),
            body: __('Send an interest to take the next step.'),
            url: route('member.profile.show', ['profile' => $other->code]),
            actorCode: $other->code,
            actorName: $card->name,
            actorPhotoThumb: $card->photoUrl,
        );
    }
}
