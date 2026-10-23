<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Broker\BureauGate;
use App\Domain\Broker\TrustedDevices;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `broker.device` (PRD §8.1): an Owner or Manager on a device that never proved an OTP is sent to
 * the device check (/broker/verify-device) before any other portal page; the page they wanted is
 * remembered. Other staff roles are not challenged. A Livewire update from an untrusted device
 * gets a 403 (it can only come from a page that was itself checked — or from a stolen session).
 */
final class EnsureBrokerDeviceTrusted
{
    public function __construct(
        private readonly BureauGate $gate,
        private readonly TrustedDevices $devices,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');
        $staff = $this->gate->staff($user instanceof User ? $user : null);

        if ($user instanceof User && $staff !== null && in_array($staff->role, TrustedDevices::ROLES, true) && ! $this->devices->isTrusted($user, $request)) {
            if ($request->hasHeader('X-Livewire')) {
                abort(403, __('Please confirm this device first.'));
            }

            if ($request->isMethod('GET')) {
                $request->session()->put('url.intended', $request->fullUrl());
            }

            return redirect()->route('broker.device');
        }

        return $next($request);
    }
}
