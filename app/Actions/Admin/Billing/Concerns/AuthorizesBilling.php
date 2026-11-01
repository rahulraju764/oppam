<?php

declare(strict_types=1);

namespace App\Actions\Admin\Billing\Concerns;

use App\Models\AdminUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** What every A06 Action starts with: the permission, and a typed reason where one is asked for. */
trait AuthorizesBilling
{
    /** @throws AuthorizationException */
    private function authorizeFor(AdminUser $admin, string $permission): void
    {
        Gate::forUser($admin)->authorize($permission);
    }

    /** @throws ValidationException */
    private function validatedReason(string $reason, string $field = 'reason', int $max = 500): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5 || mb_strlen($reason) > $max) {
            throw ValidationException::withMessages([$field => __('Give a reason of 5 to :max characters (kept in the audit log).', ['max' => $max])]);
        }

        return $reason;
    }
}
