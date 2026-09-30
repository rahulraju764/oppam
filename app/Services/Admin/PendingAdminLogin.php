<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\AdminUser;
use Illuminate\Contracts\Session\Session;

/**
 * The "password accepted, second factor not yet" state between the login form and the 2FA
 * challenge / enrolment (A01). Lives in the session for 10 minutes; the admin is NOT signed in
 * to the guard until the second factor succeeds.
 */
final class PendingAdminLogin
{
    private const ID_KEY = 'admin.pending_login.id';

    private const EXPIRES_KEY = 'admin.pending_login.expires_at';

    public function __construct(private readonly Session $session) {}

    public function start(AdminUser $admin): void
    {
        $this->session->put(self::ID_KEY, $admin->id);
        $this->session->put(self::EXPIRES_KEY, now()->addMinutes(config('oppam.admin.pending_login_minutes'))->getTimestamp());
    }

    public function admin(): ?AdminUser
    {
        $id = $this->session->get(self::ID_KEY);
        $expiresAt = (int) $this->session->get(self::EXPIRES_KEY, 0);

        if (! is_string($id) || $expiresAt < now()->getTimestamp()) {
            $this->clear();

            return null;
        }

        $admin = AdminUser::query()->find($id);

        return $admin instanceof AdminUser && $admin->isActive() && ! $admin->isLocked() ? $admin : null;
    }

    public function clear(): void
    {
        $this->session->forget([self::ID_KEY, self::EXPIRES_KEY]);
    }
}
