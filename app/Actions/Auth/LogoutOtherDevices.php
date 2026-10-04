<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\Admin\ImpersonationRestricted;
use App\Models\User;
use App\Services\Admin\Impersonation;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Log out other devices" (M01 "Logout everywhere"). Bumps users.session_epoch — every other
 * session still carries the old value and is ended on its next request by
 * EnsureMemberSessionIsValid — and rotates the remember token so other devices' "stay logged in"
 * cookies stop working. This session is re-stamped (and gets a new id) and stays signed in; if
 * this device had "stay logged in", its cookie is re-issued with the new token. Works on any
 * session driver (Redis sessions can't be listed per user). The live ForceLogout broadcast that
 * closes other tabs instantly is added with the real-time layer (P3.1).
 */
final class LogoutOtherDevices
{
    public function __construct(
        private readonly Session $session,
        private readonly AuthFactory $auth,
        private readonly Impersonation $impersonation,
    ) {}

    /** @throws ImpersonationRestricted an admin impersonating the member (A01: sessions are the member's) */
    public function handle(User $user, Request $request): void
    {
        $this->impersonation->assertAllowed();

        $epoch = DB::transaction(function () use ($user): int {
            $fresh = User::query()->lockForUpdate()->findOrFail($user->id);
            $fresh->forceFill([
                'session_epoch' => $fresh->session_epoch + 1,
                'remember_token' => Str::random(60),
            ])->save();

            return $fresh->session_epoch;
        });

        $user->refresh();

        $this->session->put(SignInMember::EPOCH_SESSION_KEY, $epoch);
        $this->session->migrate(true);

        // Keep THIS device's "stay logged in": re-issue its cookie with the new token.
        $guard = $this->auth->guard('web');

        if ($guard instanceof SessionGuard && $request->cookies->has($guard->getRecallerName())) {
            $guard->login($user, remember: true);
        }
    }
}
