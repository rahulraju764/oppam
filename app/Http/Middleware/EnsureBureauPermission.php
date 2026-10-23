<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Broker\BureauGate;
use App\Enums\BureauPermission;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `bureau:profiles.import` — the route's bureau permission (PRD §11A B.11 "Min. permission"),
 * decided by BureauGate on every request: 403 with the denial code in `X-Error-Code`
 * (BROKER_KYC_REQUIRED, BROKER_INACTIVE, BUREAU_PERMISSION). Components and Actions check again.
 */
final class EnsureBureauPermission
{
    public function __construct(private readonly BureauGate $gate) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user('web');
        $this->gate->authorize($user instanceof User ? $user : null, BureauPermission::from($permission));

        return $next($request);
    }
}
