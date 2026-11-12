<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Settings\SiteIdentity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A15 site name (P8.4): applied to config('oppam.site.name') at the start of every web request. */
final class ApplySiteIdentity
{
    public function __construct(private readonly SiteIdentity $identity) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->identity->applyName();

        return $next($request);
    }
}
