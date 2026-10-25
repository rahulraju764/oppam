<?php

declare(strict_types=1);

namespace App\Actions\Broker;

use App\Enums\ProfileOwnerType;
use App\Models\Profile;
use App\Models\User;
use App\Services\Admin\Impersonation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Wizard step 1 "referral code" (§11A B.5): a member who didn't give a code at registration — or
 * mistyped one — may enter it while their own profile was never published and has no verified
 * attribution. A verified attribution is first-touch and permanent; only admins change it
 * (R-M13-3), so it is never touched here. A support session can't set it.
 */
final class EnterReferralCode
{
    public function __construct(
        private readonly Impersonation $impersonation,
        private readonly ResolveBrokerCode $resolve,
    ) {}

    /** @throws AuthorizationException */
    public function handle(User $actor, Profile $profile, string $code): void
    {
        $this->impersonation->assertAllowed();
        Gate::forUser($actor)->authorize('editWizard', $profile);

        if (! self::canEnter($profile)) {
            return;
        }

        $this->resolve->handle($profile, $code);
    }

    /** Whether step 1 offers the field at all. */
    public static function canEnter(Profile $profile): bool
    {
        return $profile->owner_type === ProfileOwnerType::Self
            && $profile->published_at === null
            && ! $profile->broker_verified
            && $profile->broker_id === null;
    }
}
