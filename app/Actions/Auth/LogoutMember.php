<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Session\Session;

/** Sign out of this device only (M01): end the session and rotate the CSRF token. */
final class LogoutMember
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Session $session,
    ) {}

    public function handle(): void
    {
        $this->auth->guard('web')->logout();

        $this->session->invalidate();
        $this->session->regenerateToken();
    }
}
