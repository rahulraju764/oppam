<?php

declare(strict_types=1);

namespace App\Actions\Admin\Content;

use Illuminate\Validation\ValidationException;

/** The typed reason A08's archive and withdraw ask for (kept in the audit log). */
final class ReasonRule
{
    /** @throws ValidationException */
    public static function check(string $reason): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => __('Give a reason of 5 to 500 characters (kept in the audit log).')]);
        }

        return $reason;
    }
}
