<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Actions\Auth\SignInMember;
use App\Events\Account\ForceLogout;
use App\Models\User;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Every other device out, this one stays" (M01 logout everywhere; M14 mobile / password change):
 * under a row lock, bump users.session_epoch (other sessions end on their next request —
 * EnsureMemberSessionIsValid) and rotate the remember token (other "stay logged in" cookies stop
 * working), together with any change the caller makes to the same row. Then this session is
 * re-stamped (same id), this device's own "stay logged in" cookie is re-issued, and once the
 * response has gone out `.session.revoked` tells every open tab to reload (other devices land
 * signed out; this browser's tabs already hold the new session cookie and stay in).
 */
final class SessionRotation
{
    public function __construct(
        private readonly Session $session,
        private readonly AuthFactory $auth,
    ) {}

    /**
     * @param  (Closure(User): void)|null  $change  more changes to the locked row, saved with the rotation
     * @return User the refreshed user
     */
    public function rotateKeepingThisDevice(User $user, Request $request, ?Closure $change = null): User
    {
        $epoch = DB::transaction(function () use ($user, $change): int {
            $fresh = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($change !== null) {
                $change($fresh);
            }
            $fresh->forceFill([
                'session_epoch' => $fresh->session_epoch + 1,
                'remember_token' => Str::random(60),
            ])->save();

            $userId = (string) $fresh->id;
            DB::afterCommit(static function () use ($userId): void {
                // AFTER the response: this tab reloads on the signal too, and must already hold the
                // new session cookie (session->migrate below) — sent earlier, it would reload with the
                // old, now stale session and be signed out (found by Dusk). Best-effort, like LiveSignal.
                defer(static fn () => rescue(static fn () => event(new ForceLogout($userId)), report: true));
            });

            return $fresh->session_epoch;
        });

        $user->refresh();

        // Same session id, new epoch: the session is saved before the deferred signal goes out, so
        // this browser's tabs reload with their existing cookie and stay in. (A new id would need
        // the new cookie to reach the browser first — `php artisan serve` delivers it only after
        // the deferred work, so this very tab was signed out; found by Dusk.)
        $this->session->put(SignInMember::EPOCH_SESSION_KEY, $epoch);

        $guard = $this->auth->guard('web');
        if ($guard instanceof SessionGuard && $request->cookies->has($guard->getRecallerName())) {
            $guard->login($user, remember: true);
        }

        return $user;
    }
}
