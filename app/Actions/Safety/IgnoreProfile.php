<?php

declare(strict_types=1);

namespace App\Actions\Safety;

use App\Domain\Profile\ProfileVisibility;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Safety\SafetyRefused;
use App\Models\Ignore;
use App\Models\Profile;
use App\Models\User;
use App\Services\Admin\Impersonation;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Ignore another member (PRD M09 "Ignore (one-way hide)"): they leave THIS member's search and
 * lists; the other member is not affected and is never told — no event, no notification. A
 * repeat changes nothing. The member must be able to see the profile (else 404). Shares the
 * `safety.blocks_max_per_hour` limit with blocks. Never during an admin support session.
 */
final class IgnoreProfile
{
    public function __construct(
        private readonly ProfileVisibility $visibility,
        private readonly RateLimiter $limiter,
        private readonly Impersonation $impersonation,
    ) {}

    /**
     * @throws ImpersonationRestricted
     * @throws NotFoundHttpException
     * @throws SafetyRefused
     */
    public function handle(User $member, Profile $target): void
    {
        $this->impersonation->assertAllowed();

        $me = $member->isActive() ? $member->profile : null;
        if ($me === null || $me->id === $target->id || ! $this->visibility->canView($target, $member)) {
            throw new NotFoundHttpException;
        }

        if (Ignore::query()->where('ignorer_profile_id', $me->id)->where('ignored_profile_id', $target->id)->exists()) {
            return;
        }

        BlockProfile::throttle($this->limiter, $me);

        Ignore::query()->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'ignorer_profile_id' => $me->id,
            'ignored_profile_id' => $target->id,
            'created_at' => now(),
        ]);
    }
}
