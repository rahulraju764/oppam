<?php

declare(strict_types=1);

namespace App\Domain\Engagement;

use App\Enums\EngagementSignalType;
use App\Enums\SettingKey;
use App\Exceptions\Engagement\EngagementRefused;
use App\Models\Profile;
use App\Support\Facades\Settings;
use Illuminate\Cache\RateLimiter;

/**
 * Like spam guard (R-M06-2): more than `likes.spam_max_per_10_minutes` new likes inside 10 minutes
 * soft-blocks the member from liking for `likes.spam_block_minutes` and raises an A13 signal
 * (EngagementSignals: one open row per member, repeats counted). Only new likes count — unliking and repeating an existing like don't.
 * ToggleLike calls check() before a new like and record() after it is stored.
 */
final class LikeSpamGuard
{
    public const WINDOW_SECONDS = 600;

    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly EngagementSignals $signals,
    ) {}

    /** @throws EngagementRefused while soft-blocked, or when this like would be one too many */
    public function check(Profile $liker): void
    {
        if ($this->limiter->tooManyAttempts($this->blockKey($liker), 1)) {
            throw EngagementRefused::likeSpam((int) ceil($this->limiter->availableIn($this->blockKey($liker)) / 60));
        }

        $max = Settings::int(SettingKey::LikesSpamMaxPer10Minutes);

        if ($this->limiter->attempts($this->countKey($liker)) >= $max) {
            $minutes = Settings::int(SettingKey::LikesSpamBlockMinutes);
            $this->limiter->hit($this->blockKey($liker), $minutes * 60);
            $this->limiter->clear($this->countKey($liker));
            $this->signal($liker, $max, $minutes);

            throw EngagementRefused::likeSpam($minutes);
        }
    }

    public function record(Profile $liker): void
    {
        $this->limiter->hit($this->countKey($liker), self::WINDOW_SECONDS);
    }

    private function signal(Profile $liker, int $max, int $minutes): void
    {
        $this->signals->raise($liker->id, EngagementSignalType::LikeSpam,
            ['likes' => $max, 'window_minutes' => intdiv(self::WINDOW_SECONDS, 60), 'blocked_minutes' => $minutes]);
    }

    private function countKey(Profile $liker): string
    {
        return 'likes:recent:'.$liker->id;
    }

    private function blockKey(Profile $liker): string
    {
        return 'likes:blocked:'.$liker->id;
    }
}
