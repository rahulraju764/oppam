<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use RuntimeException;

/**
 * Registration refused before anything is looked up (the per-IP cap). There is deliberately no
 * "number / email already registered" error: registration never reveals that (decisions
 * 2026-09-29, R-M01-4). $field names the form field the message belongs under.
 */
final class RegistrationFailed extends RuntimeException
{
    public function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }

    public static function tooManyAttempts(int $seconds): self
    {
        return new self(__('Too many registrations from your network. Please try again in :minutes minutes.', ['minutes' => max(1, (int) ceil($seconds / 60))]), 'mobile');
    }
}
