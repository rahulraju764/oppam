<?php

declare(strict_types=1);

namespace App\Exceptions\Chat;

use RuntimeException;

/** A break-glass grant that has expired or been ended: the conversation can no longer be read through it. */
final class BreakGlassEnded extends RuntimeException
{
    public static function make(): self
    {
        return new self(__('This break-glass session has ended. Open a new one, with its own reason, to read again.'));
    }
}
