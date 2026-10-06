<?php

declare(strict_types=1);

namespace App\Exceptions\Engagement;

use RuntimeException;

/**
 * A like / favourite the member may not make right now, with a reason they can be shown
 * (PRD M06). `reason` is a stable key for tests and the UI; the message is member-facing.
 */
final class EngagementRefused extends RuntimeException
{
    public const PROFILE_NOT_ACTIVE = 'profile_not_active';

    public const LIKE_SPAM = 'like_spam';

    public const TOO_FAST = 'too_fast';

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

    public static function tooFast(): self
    {
        return new self(self::TOO_FAST, __('That was a lot in a short time. Please wait a minute and try again.'));
    }
}
