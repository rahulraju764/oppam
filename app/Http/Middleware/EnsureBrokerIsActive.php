<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Broker\BureauGate;
use App\Http\Middleware\Concerns\SignsBrokerOut;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `broker.active` (PRD §8.1, B.3, R-A07-8): the login's bureau must be APPLIED, KYC_PENDING or
 * ACTIVE. A SUSPENDED, DEACTIVATED or REJECTED bureau — or a BROKER login without a bureau — is
 * signed out on its next request with the reason. Checked against the database every time.
 */
final class EnsureBrokerIsActive
{
    use SignsBrokerOut;

    public function __construct(private readonly BureauGate $gate) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');
        $portal = $this->gate->portal($user instanceof User ? $user : null);
        if ($portal->denied() && in_array($portal->code(), [BureauGate::INACTIVE, BureauGate::NOT_STAFF], true)) {
            return $this->signOut($request, (string) $portal->message());
        }

        return $next($request);
    }
}
