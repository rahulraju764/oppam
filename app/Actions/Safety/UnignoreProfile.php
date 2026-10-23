<?php

declare(strict_types=1);

namespace App\Actions\Safety;

use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Safety\SafetyRefused;
use App\Models\Ignore;
use App\Models\Profile;
use App\Models\User;
use App\Services\Admin\Impersonation;
use Illuminate\Cache\RateLimiter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Show an ignored member again (/settings/blocked): only the member's own ignore row; 404 if there is none. */
final class UnignoreProfile
{
    public function __construct(
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
        $row = $me === null ? null : Ignore::query()->where('ignorer_profile_id', $me->id)->where('ignored_profile_id', $target->id)->first();
        if ($me === null || $row === null) {
            throw new NotFoundHttpException;
        }

        BlockProfile::throttle($this->limiter, $me);

        $row->delete();
    }
}
