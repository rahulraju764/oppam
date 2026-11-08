<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\Admin\ImpersonationRestricted;
use App\Models\User;
use App\Services\Admin\Impersonation;
use App\Support\Auth\SessionRotation;
use Illuminate\Http\Request;

/**
 * "Log out other devices" (M01 "Logout everywhere"; M14 Account tab). SessionRotation bumps
 * users.session_epoch — every other session still carries the old value and is ended on its next
 * request by EnsureMemberSessionIsValid — rotates the remember token so other devices' "stay
 * logged in" cookies stop working, keeps this device signed in, and after commit sends the live
 * ForceLogout (`.session.revoked`) so other devices' open tabs leave at once (P8.2b). Works on any
 * session driver (Redis sessions can't be listed per user).
 */
final class LogoutOtherDevices
{
    public function __construct(
        private readonly Impersonation $impersonation,
        private readonly SessionRotation $rotation,
    ) {}

    /** @throws ImpersonationRestricted an admin impersonating the member (A01: sessions are the member's) */
    public function handle(User $user, Request $request): void
    {
        $this->impersonation->assertAllowed();

        $this->rotation->rotateKeepingThisDevice($user, $request);
    }
}
