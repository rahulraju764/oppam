<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Queries\Content\LegalVersions;
use App\Services\Admin\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forced re-acceptance (A08, owner 2026-11-06): a signed-in member or bureau user who hasn't agreed
 * to the newest material terms / privacy version is sent to the accept page before anything else.
 * Once everything is agreed the session remembers which versions it checked, so later requests cost
 * no query until a new version is published. An admin support session is never stopped (and can't
 * agree for the member).
 */
final class EnsureLegalTermsAccepted
{
    public const SESSION_KEY = 'legal.checked';

    public function __construct(
        private readonly LegalVersions $versions,
        private readonly Impersonation $impersonation,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');
        if ($user === null || $this->impersonation->isActive()) {
            return $next($request);
        }

        $fingerprint = self::fingerprint($this->versions->current(), (string) $user->getAuthIdentifier());
        if ($fingerprint === null || $request->session()->get(self::SESSION_KEY) === $fingerprint) {
            return $next($request);
        }

        $pending = $this->versions->pendingFor($user);
        if ($pending->blocks()) {
            // A Livewire call on a page that was open before the new terms: a redirect, which Livewire
            // follows as a full-page visit, instead of its 409 error dialog (P9.3b). The page the member was
            // on (the Referer, same host only) becomes the return address — never /livewire/update itself.
            if ($request->hasHeader('X-Livewire')) {
                // Same scheme + host + port, as a path under the site root: `javascript://host/…`, another port and
                // `host.evil.com` all fail this (review P9.3b M2) — the login screens redirect to url.intended as is.
                $page = (string) $request->headers->get('referer', '');
                if (str_starts_with($page, $request->getSchemeAndHttpHost().'/')) {
                    $request->session()->put('url.intended', $page);
                }

                return redirect()->route('legal.accept');
            }

            // Only a page the member opened is somewhere to come back to. A background request
            // (Echo's channel auth POST, fetch) is refused without becoming the return address:
            // found by Dusk, the accept page sent members to GET /broadcasting/auth.
            if (! $request->isMethod('GET') || $request->expectsJson() || $request->ajax()) {
                abort(409, __('Please accept the updated terms to continue.'));
            }
            $request->session()->put('url.intended', $request->fullUrl());

            return redirect()->route('legal.accept');
        }

        if ($pending->notices === []) {
            $request->session()->put(self::SESSION_KEY, $fingerprint);
        }

        return $next($request);
    }

    /**
     * The versions checked, for this user only (another sign-in in the same browser checks again).
     *
     * @param  array<string, array{live: int, code: string, id: string, material: int}>  $current
     */
    public static function fingerprint(array $current, string $userId): ?string
    {
        return $current === [] ? null : hash('xxh128', $userId.'|'.json_encode($current));
    }
}
