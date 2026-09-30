<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds every Livewire component to its surface's host on the shared update endpoint.
 *
 * Livewire has ONE update URL for every host. Its "persistent middleware" replays the original
 * page's route middleware — but it looks that route up on the CURRENT host, so an admin
 * component's snapshot posted to the member host matched no admin route and ran with none of the
 * admin checks (IP allowlist, session registry, timeouts, 2FA). Found in the P0.5 review.
 *
 * Rule: components named admin.* (App\Livewire\Admin\*) run only on the admin domain, and the
 * admin domain runs nothing else. The name comes from the snapshot, which Livewire checksums, so
 * it can't be forged without invalidating the request.
 */
final class EnsureLivewireComponentHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('*livewire.update')) {
            return $next($request);
        }

        $onAdminHost = $request->getHost() === config('oppam.admin_domain');
        $components = $request->input('components');

        foreach (is_array($components) ? $components : [] as $component) {
            $snapshot = is_array($component) && is_string($component['snapshot'] ?? null)
                ? json_decode($component['snapshot'], true)
                : null;
            $name = is_array($snapshot) ? ($snapshot['memo']['name'] ?? null) : null;

            abort_unless(is_string($name), 404);
            abort_if(str_starts_with($name, 'admin.') !== $onAdminHost, 404);
        }

        return $next($request);
    }
}
