<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Admin\Impersonation\EndImpersonation;
use App\Enums\ImpersonationEndReason;
use App\Models\AdminUser;
use App\Models\ImpersonationSession;
use App\Services\Admin\Impersonation;
use App\Services\Audit\AuditLogger;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Appended to the `web` group (after EnsureMemberSessionIsValid), so it runs on every member
 * page AND Livewire update request. A session marked as an impersonation stays signed in only
 * while its impersonation_sessions row is running (not ended, inside its 30 minutes), belongs to
 * the signed-in member, and its admin is still active with `members.impersonate`. Otherwise the
 * row is closed (EXPIRED, or REVOKED for a lost admin) and the browser is signed out of THIS
 * session only — logoutCurrentDevice() leaves the member's own "stay logged in" token alone.
 *
 * Every data-changing request made while impersonating is written to the audit log as
 * `impersonation.action` with the admin as actor (owner decision 2026-10-03): the route or the
 * Livewire components, methods and field names — never the values. The handoff route itself is
 * left alone (it replaces the browser's session, including an earlier impersonation).
 */
final class EnsureImpersonationIsValid
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly EndImpersonation $end,
        private readonly Impersonation $impersonation,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $request->session()->has(Impersonation::SESSION_KEY) || $request->routeIs('impersonation.enter')) {
            return $next($request);
        }

        $id = $request->session()->get(Impersonation::SESSION_KEY);
        $row = is_string($id) ? ImpersonationSession::query()->with('admin')->whereKey($id)->first() : null;
        $user = $request->user('web');
        $admin = $row?->admin;
        $adminAllowed = $admin instanceof AdminUser && $admin->isActive() && ! $admin->isLocked() && Gate::forUser($admin)->allows('members.impersonate');

        if ($row !== null && $row->isRunning() && $adminAllowed && $user !== null && $user->getAuthIdentifier() === $row->user_id) {
            // Recorded BEFORE the request runs: a request that fails half-way (or is rejected) is
            // still on record, and a crafted payload can't skip the row by making the parser fail.
            $this->recordAction($request, $row, $admin);

            return $next($request);
        }

        if ($row !== null && $row->ended_at === null) {
            $this->end->handle($row, $adminAllowed ? ImpersonationEndReason::Expired : ImpersonationEndReason::Revoked);
        }

        $guard = $this->auth->guard('web');
        if ($guard instanceof SessionGuard) {
            $guard->logoutCurrentDevice();
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $this->impersonation->forget();

        return redirect()->route('login')->with('status', __('The support session has ended.'));
    }

    /** The audit trail of what the admin did as the member: structure only, never submitted values. */
    private function recordAction(Request $request, ImpersonationSession $row, AdminUser $admin): void
    {
        if ($request->isMethodSafe() || $request->routeIs('impersonation.end')) {
            return;
        }

        $what = ['impersonation' => $row->id, 'method' => $request->method(), 'route' => $request->route()?->getName() ?? $request->path()];

        if ($request->routeIs('*livewire.update')) {
            try {
                $actions = $this->livewireActions($request->input('components'));
            } catch (Throwable) {
                $actions = null;   // never let parsing decide whether the row is written
            }

            if ($actions === []) {
                return;   // only $refresh / lazy loads: nothing can change
            }

            $what['livewire'] = $actions ?? 'unparsed';
        }

        $this->audit->record('impersonation.action', $row->user, after: $what, actor: $admin,
            subjectLabel: $row->user?->profile()->withTrashed()->value('code'));
    }

    /**
     * Component names, method names and updated field names of a Livewire update — no values.
     * `$set` / `$toggle` record the field they change. Null = a payload this parser doesn't
     * understand (recorded as "unparsed"); [] = nothing but refreshes.
     *
     * @return list<array{component: string, calls: list<string>, fields: list<string>}>|null
     */
    private function livewireActions(mixed $components): ?array
    {
        if (! is_array($components)) {
            return null;
        }

        $actions = [];
        $unparsed = false;

        foreach ($components as $component) {
            if (! is_array($component)) {
                $unparsed = true;

                continue;
            }

            $snapshot = is_string($component['snapshot'] ?? null) ? json_decode($component['snapshot'], true) : null;
            $name = is_array($snapshot) && is_array($snapshot['memo'] ?? null) && is_string($snapshot['memo']['name'] ?? null) ? $snapshot['memo']['name'] : null;
            if ($name === null) {
                $unparsed = true;

                continue;
            }

            $calls = [];
            foreach (is_array($component['calls'] ?? null) ? $component['calls'] : [] as $call) {
                $method = is_array($call) && is_string($call['method'] ?? null) ? $call['method'] : null;
                if ($method === null) {
                    $unparsed = true;

                    continue;
                }
                // Only pure re-renders are left out; an event (__dispatch) runs #[On] listeners.
                if (in_array($method, ['$refresh', '__lazyLoad', '__lazyLoadIsland'], true)) {
                    continue;
                }
                $params = is_array($call['params'] ?? null) ? $call['params'] : [];
                $calls[] = in_array($method, ['$set', '$toggle', '__dispatch'], true) && is_string($params[0] ?? null)
                    ? $method.':'.$params[0]   // the field / event name, never a value
                    : $method;
            }

            $fields = array_map('strval', array_keys(is_array($component['updates'] ?? null) ? $component['updates'] : []));

            if ($calls !== [] || $fields !== []) {
                $actions[] = ['component' => $name, 'calls' => $calls, 'fields' => $fields];
            }
        }

        if ($unparsed) {
            $actions[] = ['component' => '(unparsed)', 'calls' => [], 'fields' => []];   // still on record
        }

        return $actions;
    }
}
