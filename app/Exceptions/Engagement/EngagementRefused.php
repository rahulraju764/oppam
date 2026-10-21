<?php

declare(strict_types=1);

namespace App\Exceptions\Engagement;

use RuntimeException;

/**
 * A like / favourite / interest / request the member may not make right now, with a reason they can be shown
 * (PRD M06). `reason` is a stable key for tests and the UI; the message is member-facing.
 */
final class EngagementRefused extends RuntimeException
{
    public const PROFILE_NOT_ACTIVE = 'profile_not_active';

    public const LIKE_SPAM = 'like_spam';

    public const TOO_FAST = 'too_fast';

    public const CONTACT_FILTER = 'contact_filter';

    public const COOLDOWN = 'cooldown';

    public const ALREADY_CONNECTED = 'already_connected';

    public const CUSTOM_MESSAGE_PLAN = 'custom_message_plan';

    public const NOT_PENDING = 'not_pending';

    public const NOT_REQUESTABLE = 'not_requestable';

    public const PAUSED = 'paused';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /** Only an approved (ACTIVE) profile can like or bookmark others. */
    public static function profileNotActive(): self
    {
        return new self(self::PROFILE_NOT_ACTIVE, __('You can do this once your own profile is approved and visible.'));
    }

    /** R-M06-2: the soft block after too many likes in a short time. */
    public static function likeSpam(int $minutesLeft): self
    {
        return new self(self::LIKE_SPAM, trans_choice(
            'You’re liking profiles very quickly. Please try again in :count minute.|You’re liking profiles very quickly. Please try again in :count minutes.',
            max(1, $minutesLeft),
            ['count' => max(1, $minutesLeft)],
        ));
    }

    /** R-M06-3: the target only accepts contact from members who match their partner preferences. */
    public static function contactFilter(): self
    {
        return new self(self::CONTACT_FILTER, __('This member only accepts interests from profiles that match their partner preferences.'));
    }

    /** Declined pairs can't re-send for the cooldown (M06, `interest.resend_cooldown_days`). */
    public static function cooldown(string $until): self
    {
        return new self(self::COOLDOWN, __('You can send this member another interest after :date.', ['date' => $until]));
    }

    public static function alreadyConnected(): self
    {
        return new self(self::ALREADY_CONNECTED, __('You are already connected with this member.'));
    }

    public static function customMessageNeedsPlan(): self
    {
        return new self(self::CUSTOM_MESSAGE_PLAN, __('Writing your own message is part of the paid plans. Pick one of the ready messages, or upgrade.'));
    }

    /** The interest is no longer waiting for a reply (answered, withdrawn or expired meanwhile). */
    public static function notPending(): self
    {
        return new self(self::NOT_PENDING, __('This interest is no longer waiting for a reply.'));
    }

    /** A photo / horoscope request that isn't needed or possible (nothing to ask for, already open). */
    public static function notRequestable(): self
    {
        return new self(self::NOT_REQUESTABLE, __('There is nothing to request here right now.'));
    }

    /** A13: a moderator paused everything this member sends, until the given time (IST). */
    public static function paused(string $until): self
    {
        return new self(self::PAUSED, __('Sending likes, interests and messages is paused on your account until :time after a safety review. If you think this is a mistake, please contact support.', ['time' => $until]));
    }

    public static function tooFast(): self
    {
        return new self(self::TOO_FAST, __('That was a lot in a short time. Please wait a minute and try again.'));
    }
}
