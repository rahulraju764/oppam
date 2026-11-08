<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Enums\LoginMethod;
use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * The one step between "right password / code for a closed account" and "restore it" (M14, P8.2c).
 * The Login screen remembers which account proved itself, how, and "stay logged in"; the
 * /account/restore page reads it for `minutes` only. Nothing in it comes from the browser, and it
 * is forgotten as soon as the member restores or says "not now".
 */
final class PendingAccountRestore
{
    private const KEY = 'account_restore';

    private const MINUTES = 10;

    public function __construct(private readonly Session $session) {}

    public function remember(User $user, LoginMethod $method, bool $stayLoggedIn): void
    {
        // The member just proved who they are: a new session id now (as a sign-in does), so a
        // session id planted in this browser before can never be the one allowed to restore.
        $this->session->migrate(true);
        $this->session->regenerateToken();

        $this->session->put(self::KEY, [
            'user' => (string) $user->id,
            'method' => $method->value,
            'remember' => $stayLoggedIn,
            'until' => now()->addMinutes(self::MINUTES)->getTimestamp(),
        ]);
    }

    /** @return array{user: string, method: LoginMethod, remember: bool}|null */
    public function current(): ?array
    {
        $pending = $this->session->get(self::KEY);

        if (! is_array($pending) || ! is_string($pending['user'] ?? null) || ! is_int($pending['until'] ?? null)
            || $pending['until'] < now()->getTimestamp()) {
            return null;
        }

        $method = LoginMethod::tryFrom((string) ($pending['method'] ?? ''));

        return $method === null ? null : ['user' => $pending['user'], 'method' => $method, 'remember' => (bool) ($pending['remember'] ?? false)];
    }

    public function forget(): void
    {
        $this->session->forget(self::KEY);
    }
}
