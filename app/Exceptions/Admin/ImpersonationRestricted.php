<?php

declare(strict_types=1);

namespace App\Exceptions\Admin;

use RuntimeException;

/**
 * An action an impersonating admin may not take as the member (A01: password / email / payment;
 * owner decision 2026-10-02: also anything that spends the member's quota or contacts other
 * members on their behalf).
 */
final class ImpersonationRestricted extends RuntimeException
{
    public static function make(): self
    {
        return new self(__('Not available while our team is viewing this account for support.'));
    }
}
