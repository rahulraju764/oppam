<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use App\Models\User;
use RuntimeException;

/**
 * The member proved who they are (right password or login code) for an account they closed
 * themselves inside the recovery window (M14, P8.2c). Never raised before that proof, so it tells a
 * stranger nothing (R-M01-4). The Login screen turns it into the /account/restore step.
 */
final class AccountClosed extends RuntimeException
{
    public function __construct(public readonly User $user)
    {
        parent::__construct(__('This account was closed. You can restore it.'));
    }
}
