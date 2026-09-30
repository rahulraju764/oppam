<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use RuntimeException;

/** Too many codes requested (R-M01-2 send limits). Carries the retry-after for the countdown. */
final class OtpThrottled extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct(self::describe($retryAfterSeconds));
    }

    private static function describe(int $seconds): string
    {
        if ($seconds <= 90) {
            return __('Please wait :seconds seconds before asking for another code.', ['seconds' => $seconds]);
        }

        return __('Too many codes requested. Please try again in :minutes minutes.', ['minutes' => (int) ceil($seconds / 60)]);
    }
}
