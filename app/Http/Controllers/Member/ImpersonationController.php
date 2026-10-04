<?php

declare(strict_types=1);

namespace App\Http\Controllers\Member;

use App\Actions\Admin\Impersonation\EndImpersonation;
use App\Actions\Admin\Impersonation\EnterImpersonation;
use App\Actions\Auth\SignInMember;
use App\Enums\ImpersonationEndReason;
use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\User;
use App\Services\Admin\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The member-site end of admin impersonation (A01 / A03, P1.7b). A plain controller: it hands a
 * browser from the admin panel (another domain, another session cookie) to a member session.
 *
 * - GET /impersonate/{token}: EnterImpersonation checks the single-use token; on success any
 *   member session in this browser ends (this device only), the member is signed in WITHOUT
 *   SignInMember (no login_events row, no new-device mail — it isn't the member signing in) and
 *   the session is marked. Anything wrong → 404, no reason given. No Referer leaves the page.
 * - POST /impersonation/end: closes the row (ENDED), signs this browser out of the member
 *   session only, and shows "Support session ended" with a link back to the admin panel.
 */
final class ImpersonationController extends Controller
{
    public function enter(Request $request, string $token, EnterImpersonation $enter, Impersonation $impersonation): RedirectResponse
    {
        $session = $enter->handle($token, $request->ip());
        $member = $session !== null ? User::query()->find($session->user_id) : null;

        if ($session === null || $member === null) {
            abort(404);
        }

        $guard = Auth::guard('web');
        if ($guard->check() && method_exists($guard, 'logoutCurrentDevice')) {
            $guard->logoutCurrentDevice();
        }
        $request->session()->invalidate();

        $guard->login($member);   // no "remember me": the support session never outlives the browser session
        $request->session()->regenerate();
        $request->session()->put(SignInMember::EPOCH_SESSION_KEY, $member->session_epoch);
        $request->session()->put(Impersonation::SESSION_KEY, $session->id);
        $impersonation->forget();

        return redirect()->route('member.profile.me')->withHeaders(['Referrer-Policy' => 'no-referrer']);
    }

    public function end(Request $request, EndImpersonation $end, Impersonation $impersonation): RedirectResponse
    {
        $session = $impersonation->current();

        if ($session === null) {
            return redirect()->route('home');
        }

        $end->handle($session, ImpersonationEndReason::Ended, $session->admin);

        $guard = Auth::guard('web');
        if (method_exists($guard, 'logoutCurrentDevice')) {
            $guard->logoutCurrentDevice();
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $impersonation->forget();

        $code = Profile::withTrashed()->where('user_id', $session->user_id)->value('code');

        // A page on this site, not a redirect into the admin panel: no automatic cross-domain hop
        // (the admin cookie is SameSite=Strict); the admin follows the link back.
        return redirect()->route('impersonation.ended')->with('impersonation_ended_code', is_string($code) ? $code : null);
    }
}
