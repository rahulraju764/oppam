<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives the admin domain its own session cookie (PRD §8.1 "separate session cookie"): a
 * different name, host-only (never shared with oppam.in even if SESSION_DOMAIN is set for the
 * member site), SameSite=Strict, and the 30-minute idle lifetime. Prepended to the `web` group
 * so it runs before StartSession — including on Livewire's update endpoint, which is shared by
 * both hosts.
 */
final class ConfigureAdminSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->getHost() === config('oppam.admin_domain')) {
            config([
                'session.cookie' => config('oppam.admin.session_cookie'),
                'session.domain' => null,
                'session.same_site' => 'strict',
                'session.lifetime' => config('oppam.admin.idle_timeout_minutes'),
                'session.expire_on_close' => false,
            ]);
        }

        return $next($request);
    }
}
