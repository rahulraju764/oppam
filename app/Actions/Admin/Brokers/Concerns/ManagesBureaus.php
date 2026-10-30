<?php

declare(strict_types=1);

namespace App\Actions\Admin\Brokers\Concerns;

use App\Models\AdminUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Shared rules of the A07 bureau and payout actions (§11A B.12): the admin permission is checked
 * in the Action (the screen only hides buttons), and every change carries a typed reason that is
 * kept in the audit log.
 */
trait ManagesBureaus
{
    /** @throws AuthorizationException */
    private function authorizeFor(AdminUser $admin, string $permission): void
    {
        Gate::forUser($admin)->authorize($permission);
    }

    /** @throws ValidationException */
    private function validatedReason(string $reason, string $field = 'reason'): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 300) {
            throw ValidationException::withMessages([$field => __('Give a reason of 5 to 300 characters (kept in the audit log).')]);
        }

        return $reason;
    }
}
