<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Reports\SignupAttribution;
use Closure;
use Illuminate\Cookie\CookieJar;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A09 signup source (P9.1a): a visit with `utm_source` / `utm_medium` / `utm_campaign` sets a 30-day
 * FIRST-TOUCH cookie (never overwritten), read once at registration (RegisterMember). Values are
 * sanitised to [a-z0-9_-], 40 characters each (SignupAttribution) — nothing else is kept.
 */
final class CaptureSignupSource
{
    public const COOKIE = 'oppam_src';

    public const DAYS = 30;

    public function __construct(private readonly CookieJar $cookies) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && $request->cookie(self::COOKIE) === null) {
            $utm = SignupAttribution::fromQuery($request->query());
            if ($utm !== null) {
                $this->cookies->queue($this->cookies->make(self::COOKIE, SignupAttribution::encode($utm), self::DAYS * 24 * 60, httpOnly: true, sameSite: 'lax'));
            }
        }

        return $next($request);
    }
}
