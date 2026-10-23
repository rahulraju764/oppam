<?php

declare(strict_types=1);

namespace App\Exceptions\Safety;

use RuntimeException;

/** An A10 case action that no longer applies: the case was decided meanwhile, or the member is gone. */
final class CaseUnavailable extends RuntimeException
{
    public static function decided(): self
    {
        return new self(__('This case has already been decided.'));
    }

    public static function memberGone(): self
    {
        return new self(__('This member’s account no longer exists.'));
    }
}
