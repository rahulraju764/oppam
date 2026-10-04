<?php

declare(strict_types=1);

namespace App\Actions\Search;

use App\Domain\Profile\ProfileVisibility;
use App\Exceptions\Search\SearchThrottled;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Cache\RateLimiter;

/**
 * Search by profile ID (M04 mode 1): the profile when this member may open it, otherwise null —
 * the same answer for a code that doesn't exist and one that is hidden from them (blocked, same
 * gender, not live), so codes can't be probed. Rate-limited per member (P2.1 review), like the
 * search itself.
 */
final class FindProfileById
{
    public const MAX_PER_MINUTE = 20;

    public function __construct(
        private readonly ProfileVisibility $visibility,
        private readonly RateLimiter $limiter,
    ) {}

    /** @throws SearchThrottled */
    public function handle(User $member, string $code): ?Profile
    {
        $key = 'find-profile:'.$member->id;
        if ($this->limiter->tooManyAttempts($key, self::MAX_PER_MINUTE)) {
            throw new SearchThrottled($this->limiter->availableIn($key));
        }
        $this->limiter->hit($key, 60);

        $code = strtoupper(trim($code));
        $profile = preg_match('/^OPM\d{1,10}$/', $code) === 1 ? Profile::query()->where('code', $code)->first() : null;

        return $profile !== null && $this->visibility->canView($profile, $member) ? $profile : null;
    }
}
