<?php

declare(strict_types=1);

namespace App\Events\Profile;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A member submitted their profile for review (R-M02-2): it is now PENDING_REVIEW with an open
 * PROFILE_NEW moderation item. Dispatched after the transaction commits.
 */
final class ProfileSubmitted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $profileCode,
        public readonly string $moderationItemId,
    ) {}
}
