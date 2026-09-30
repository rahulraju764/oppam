<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\AdminSession;
use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The admin_sessions registry (A01 session list & revoke): one row per signed-in admin session.
 * The row id lives in the admin's session data, which is server-side (database / Redis) and
 * survives the id rotation at sign-in; a row only counts when it belongs to the signed-in admin.
 */
final class AdminSessionRegistry
{
    public const SESSION_KEY = 'admin.registry_id';

    /** Throttle last_seen_at writes to one per minute per session. */
    private const TOUCH_SECONDS = 60;

    public function start(AdminUser $admin, Request $request): AdminSession
    {
        $session = new AdminSession;
        $session->forceFill([
            'admin_user_id' => $admin->id,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
            'last_seen_at' => now(),
        ])->save();

        session()->put(self::SESSION_KEY, $session->id);

        return $session;
    }

    public function current(Request $request): ?AdminSession
    {
        $admin = $request->user('admin');
        $id = session()->get(self::SESSION_KEY);

        if (! $admin instanceof AdminUser || ! is_string($id)) {
            return null;
        }

        return AdminSession::query()->where('admin_user_id', $admin->id)->find($id);
    }

    public function touch(AdminSession $session): void
    {
        if ($session->last_seen_at->lt(now()->subSeconds(self::TOUCH_SECONDS))) {
            $session->forceFill(['last_seen_at' => now()])->save();
        }
    }

    public function revoke(AdminSession $session): void
    {
        if ($session->revoked_at === null) {
            $session->forceFill(['revoked_at' => now()])->save();
        }
    }

    public function revokeAllFor(AdminUser $admin): int
    {
        return AdminSession::query()->where('admin_user_id', $admin->id)->live()->update(['revoked_at' => now()]);
    }
}
