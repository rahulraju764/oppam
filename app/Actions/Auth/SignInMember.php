<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\LoginMethod;
use App\Models\LoginEvent;
use App\Models\User;
use App\Notifications\Auth\NewDeviceSignIn;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Session\Session;
use Illuminate\Cookie\CookieJar;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The one place a member/broker session starts (M01): after a verified code or password.
 * - Regenerates the session id (fixation) and stamps the user's session_epoch into it, so
 *   "log out other devices" can end this session later (EnsureMemberSessionIsValid).
 * - Records a login_events row keyed by a random device cookie, and emails a new-device alert
 *   when the device has never signed in to this account before — only to a VERIFIED email
 *   (email verification arrives with M14; a stranger's address must not get security mail).
 */
final class SignInMember
{
    public const EPOCH_SESSION_KEY = 'auth.session_epoch';

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly CookieJar $cookies,
        private readonly Session $session,
    ) {}

    /** $request supplies the IP, user agent and device cookie for the login record. */
    public function handle(User $user, LoginMethod $method, bool $remember, Request $request): void
    {
        $this->auth->guard('web')->login($user, $remember);

        $this->session->regenerate();
        $this->session->put(self::EPOCH_SESSION_KEY, $user->session_epoch);

        $this->recordLogin($user, $method, $request);
    }

    private function recordLogin(User $user, LoginMethod $method, Request $request): void
    {
        $cookieName = (string) config('oppam.auth.device_cookie');
        $deviceId = $request->cookie($cookieName);

        if (! is_string($deviceId) || strlen($deviceId) !== 40) {
            $deviceId = Str::random(40);
            $this->cookies->queue($this->cookies->forever($cookieName, $deviceId, httpOnly: true, sameSite: 'lax'));
        }

        $deviceHash = hash('sha256', $deviceId);
        $knownDevice = LoginEvent::query()->where('user_id', $user->id)->where('device_hash', $deviceHash)->exists();
        $firstSignIn = ! LoginEvent::query()->where('user_id', $user->id)->exists();

        $event = new LoginEvent;
        $event->forceFill([
            'user_id' => $user->id,
            'method' => $method,
            'device_hash' => $deviceHash,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ])->save();

        if (! $knownDevice && ! $firstSignIn && $user->email !== null && $user->email_verified_at !== null) {
            $user->notify(new NewDeviceSignIn($event));
        }
    }
}
