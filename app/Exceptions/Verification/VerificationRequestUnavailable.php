<?php

declare(strict_types=1);

namespace App\Exceptions\Verification;

use RuntimeException;

/** The request can't be decided / opened by this officer now. The message is shown to them. */
final class VerificationRequestUnavailable extends RuntimeException
{
    public static function claimedByOther(): self
    {
        return new self(__('Another officer is reviewing this request.'));
    }

    public static function alreadyDecided(): self
    {
        return new self(__('This request has already been decided.'));
    }

    public static function memberGone(): self
    {
        return new self(__('This member’s account was deleted. The request is no longer waiting.'));
    }

    public static function documentsPurged(): self
    {
        return new self(__('The documents of this request have been deleted (90 days after the decision).'));
    }
}
