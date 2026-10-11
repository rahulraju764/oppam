<?php

declare(strict_types=1);

namespace App\Notifications\Engagement;

use App\Data\Notifications\NotificationContentData;
use App\Domain\Profile\ProfileCards;
use App\Enums\AccessRequestType;
use App\Enums\NotificationEvent;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\Support\MemberNotification;

/**
 * Photo / horoscope requests (PRD M08 `photo_request` / `horoscope_request` "+ approved", M06):
 * to the owner when someone asks, and to the requester when the owner approves. A decline is
 * never announced. Never inside a blocked pair (base class).
 */
final class AccessRequestNotification extends MemberNotification
{
    public function __construct(
        public readonly AccessRequestType $type,
        public readonly string $otherProfileId,
        public readonly bool $approved = false,
    ) {}

    public function event(): NotificationEvent
    {
        return $this->type->notificationEvent();
    }

    public function actorProfileId(): string
    {
        return $this->otherProfileId;
    }

    public function content(object $notifiable): NotificationContentData
    {
        $other = Profile::query()->find($this->otherProfileId);

        if ($other === null || ! $notifiable instanceof User) {
            return new NotificationContentData(title: __('Your requests have an update'), body: __('Open Interests to see it.'), url: route('member.interests', ['box' => 'requests']));
        }

        $card = app(ProfileCards::class)->forViewer($other, $notifiable);
        $what = $this->type === AccessRequestType::Photo ? __('photos') : __('horoscope');

        return $this->approved
            ? new NotificationContentData(
                title: __(':name approved your request to see their :what', ['name' => $card->name, 'what' => $what]),
                body: __('Open their profile to take a look.'),
                url: route('member.profile.show', ['profile' => $other->code]),
                actorCode: $other->code, actorName: $card->name, actorPhotoThumb: $card->photoUrl)
            : new NotificationContentData(
                title: __(':name asked to see your :what', ['name' => $card->name, 'what' => $what]),
                body: __('Approve or decline in Interests.'),
                url: route('member.interests', ['box' => 'requests']),
                actorCode: $other->code, actorName: $card->name, actorPhotoThumb: $card->photoUrl);
    }
}
