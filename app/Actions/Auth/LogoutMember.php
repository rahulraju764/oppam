<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Admin\Impersonation\EndImpersonation;
use App\Enums\ImpersonationEndReason;
use App\Services\Admin\Impersonation;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Session\Session;

/**
 * Sign out of this device only (M01): end the session and rotate the CSRF token, without rotating
 * the remember token (guard->logout() would, signing every other "stay logged in" device out
 * too). During an admin impersonation (P1.7b) it also closes the impersonation (SIGNED_OUT).
 */
final class LogoutMember
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Session $session,
        private readonly Impersonation $impersonation,
        private readonly EndImpersonation $end,
    ) {}

    public function handle(): void
    {
        $guard = $this->auth->guard('web');
        $impersonating = $this->session->has(Impersonation::SESSION_KEY);

        if ($impersonating) {
            $running = $this->impersonation->current();
            if ($running !== null) {
                $this->end->handle($running, ImpersonationEndReason::SignedOut);
            }
        }

        // This device only (M01): logoutCurrentDevice() never rotates the remember token, so the
        // member's other "stay logged in" devices — and, while impersonating, the member's own
        // devices — stay signed in. "Log out other devices" is the way to end those.
        if ($guard instanceof SessionGuard) {
            $guard->logoutCurrentDevice();
        } else {
            $guard->logout();
        }

        $this->session->invalidate();
        $this->session->regenerateToken();
        $this->impersonation->forget();
    }
}
