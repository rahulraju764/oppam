<?php

declare(strict_types=1);

namespace App\Notifications\Engagement;

use App\Data\Notifications\NotificationContentData;
use App\Domain\Profile\ProfileCards;
use App\Enums\NotificationEvent;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\Support\MemberNotification;
use InvalidArgumentException;

/**
 * The three interest notifications (PRD M08, M06 / F02), all about one other member:
 *  - interest_received: "… sent you an interest" (in-app, email, SMS by default);
 *  - interest_accepted: "… accepted your interest" — the two are now connected;
 *  - interest_declined: a polite "not interested at this time" (in-app only by default).
 * The other member is named as the recipient may see them (masked until connected). Never inside
 * a blocked pair (base class).
 */
final class InterestNotification extends MemberNotification
{
    public const EVENTS = [NotificationEvent::InterestReceived, NotificationEvent::InterestAccepted, NotificationEvent::InterestDeclined];

    public function __construct(public readonly NotificationEvent $kind, public readonly string $otherProfileId)
    {
        if (! in_array($kind, self::EVENTS, true)) {
            throw new InvalidArgumentException('Not an interest notification: '.$kind->value);
        }
    }

    public function event(): NotificationEvent
    {
        return $this->kind;
    }

    public function actorProfileId(): string
    {
        return $this->otherProfileId;
    }

    public function content(object $notifiable): NotificationContentData
    {
        $other = Profile::query()->find($this->otherProfileId);

        if ($other === null || ! $notifiable instanceof User) {
            return new NotificationContentData(title: __('Your interests have an update'), body: __('Open Interests to see it.'), url: route('member.interests'));
        }

        $card = app(ProfileCards::class)->forViewer($other, $notifiable);

        [$title, $body, $url] = match ($this->kind) {
            NotificationEvent::InterestAccepted => [
                __(':name accepted your interest', ['name' => $card->name]),
                __('You are now connected. Say hello!'),
                route('member.profile.show', ['profile' => $other->code]),
            ],
            NotificationEvent::InterestDeclined => [
                __(':name is not interested at this time', ['name' => $card->name]),
                __('Don’t lose heart — there are more matches waiting for you.'),
                route('member.matches'),
            ],
            default => [
                __(':name sent you an interest', ['name' => $card->name]),
                __('Accept to connect, or decline politely.'),
                route('member.interests'),
            ],
        };

        return new NotificationContentData(title: $title, body: $body, url: $url,
            actorCode: $other->code, actorName: $card->name, actorPhotoThumb: $card->photoUrl);
    }

    /** DLT templates take the other member's profile code. */
    public function smsVariables(object $notifiable): array
    {
        return [(string) Profile::query()->whereKey($this->otherProfileId)->value('code')];
    }
}
