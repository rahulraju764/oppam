<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `2fa.confirmed` — no admin page without two-factor (PRD §8.4 "2FA for every admin"): the admin
 * must have confirmed TOTP enrolment AND passed the challenge in this session. Belt and braces
 * with admin.session (a session row only exists after the challenge).
 */
final class EnsureTwoFactorConfirmed
{
    public const SESSION_KEY = 'admin.two_factor_passed_at';

    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        if ($admin instanceof AdminUser && (! $admin->hasConfirmedTwoFactor() || ! $request->session()->has(self::SESSION_KEY))) {
            auth('admin')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('admin.login')->with('status', __('Please sign in with your authenticator code.'));
        }

        return $next($request);
    }
}
