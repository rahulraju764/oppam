<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cookie\CookieJar;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `?ref=BRK1042` on any public page sets a 30-day FIRST-TOUCH cookie (R-M13-9): an existing
 * cookie is never overwritten. The code is only normalised here (upper-case, spaces removed —
 * R-M13-2); it is resolved against brokers at registration from P7.2, and an unknown code never
 * blocks anything (R-M13-1).
 */
final class CaptureReferralCode
{
    public function __construct(private readonly CookieJar $cookies) {}

    public function handle(Request $request, Closure $next): Response
    {
        $cookieName = (string) config('oppam.auth.referral_cookie');
        $ref = $request->query('ref');

        if ($request->isMethod('GET') && is_string($ref) && $request->cookie($cookieName) === null) {
            $code = strtoupper((string) preg_replace('/\s+/', '', $ref));

            if (preg_match('/^[A-Z0-9]{1,20}$/', $code) === 1) {
                $this->cookies->queue($this->cookies->make(
                    $cookieName,
                    $code,
                    (int) config('oppam.auth.referral_cookie_days') * 24 * 60,
                    httpOnly: true,
                    sameSite: 'lax',
                ));
            }
        }

        return $next($request);
    }
}
