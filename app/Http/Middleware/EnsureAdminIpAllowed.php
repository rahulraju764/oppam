<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * `ip.allowlist` — optional network restriction for the whole admin domain (PRD §8.1). Empty
 * list (the default) = no restriction. Applies to the login page too. Behind a load balancer,
 * configure TrustProxies so $request->ip() is the client address.
 */
final class EnsureAdminIpAllowed
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $allowed */
        $allowed = config('oppam.admin.ip_allowlist', []);

        abort_if($allowed !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowed), 403);

        return $next($request);
    }
}
