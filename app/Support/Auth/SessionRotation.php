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
 * working), together with any change the caller makes to the same row (it runs inside the same
 * transaction). Then this session is re-stamped and gets a NEW id (a stolen copy of the old cookie
 * dies with it), and this device's own "stay logged in" cookie is re-issued.
 *
 * The live `.session.revoked` (ForceLogout) goes out through the `broadcasts` queue after commit,
 * `oppam.auth.force_logout_delay_seconds` later: every open tab reloads on it — this one too — so
 * this browser must already hold the new session cookie from this response. Sent at once, it beat
 * the response on the local server and signed the member out on their own device (found by Dusk).
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

            // Best-effort, like LiveSignal: a queue that can't take the job (e.g. Redis down) is
            // reported, never turns a done change into an error; the epoch already ends the sessions.
            $userId = (string) $fresh->id;
            DB::afterCommit(static function () use ($userId): void {
                rescue(static function () use ($userId): void {
                    // A statement, not a return value: PendingDispatch pushes when destroyed — here, inside rescue.
                    dispatch(static function () use ($userId): void {
                        event(new ForceLogout($userId));
                    })->onQueue('broadcasts')->delay(now()->addSeconds((int) config('oppam.auth.force_logout_delay_seconds')));
                }, report: true);
            });

            return $fresh->session_epoch;
        });

        $user->refresh();

        $this->session->put(SignInMember::EPOCH_SESSION_KEY, $epoch);
        $this->session->migrate(true);

        $guard = $this->auth->guard('web');
        if ($guard instanceof SessionGuard && $request->cookies->has($guard->getRecallerName())) {
            $guard->login($user, remember: true);
        }

        return $user;
    }
}
