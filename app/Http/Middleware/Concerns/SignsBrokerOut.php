<?php

declare(strict_types=1);

namespace App\Http\Middleware\Concerns;

use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends THIS session of a bureau login whose bureau or login stopped being usable, and sends it to
 * the login page with the reason — on a page load and on a Livewire update alike (persistent
 * middleware). The remember token is left alone (as EnsureMemberSessionIsValid).
 */
trait SignsBrokerOut
{
    private function signOut(Request $request, string $message): Response
    {
        $guard = auth('web');
        if ($guard instanceof SessionGuard) {
            $guard->logoutCurrentDevice();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', $message);
    }
}
