<?php

declare(strict_types=1);

namespace App\Actions\Admin\Brokers\Concerns;

use App\Models\AdminUser;
use App\Models\Profile;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Shared rules of the A07 unverified-codes decisions (R-M13-3 "changes are admin-only and
 * audited"): `brokers.edit`, a typed reason, and the profile row locked and re-checked — it must
 * still carry an unverified code — so two admins can't decide the same row.
 */
trait ReviewsReferralCodes
{
    /** @throws AuthorizationException */
    private function authorizeReview(AdminUser $admin): void
    {
        Gate::forUser($admin)->authorize('brokers.edit');
    }

    /** @throws ValidationException */
    private function validatedReason(string $reason): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 300) {
            throw ValidationException::withMessages(['reason' => __('Give a reason of 5 to 300 characters.')]);
        }

        return $reason;
    }

    /** @throws ValidationException */
    private function lockUnverified(Profile $profile): Profile
    {
        $current = Profile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();

        if ($current->broker_code_raw === null || $current->broker_verified || $current->broker_id !== null) {
            throw ValidationException::withMessages(['reason' => __('This code has already been reviewed.')]);
        }

        return $current;
    }
}
