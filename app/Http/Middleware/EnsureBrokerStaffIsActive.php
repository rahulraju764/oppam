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
 * `broker.staff.active` (PRD §8.1, R-M13-18): the login's `broker_staff` row must be ACTIVE — or be
 * the applicant Owner (INVITED until KYC approval, who reaches only the pre-KYC pages through
 * BureauGate). A deactivated staff login is signed out on its next request (B.19).
 */
final class EnsureBrokerStaffIsActive
{
    use SignsBrokerOut;

    public function __construct(private readonly BureauGate $gate) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');
        $portal = $this->gate->portal($user instanceof User ? $user : null);

        if ($portal->denied()) {
            return $this->signOut($request, (string) $portal->message());
        }

        return $next($request);
    }
}
