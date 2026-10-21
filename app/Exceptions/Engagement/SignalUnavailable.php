<?php

declare(strict_types=1);

namespace App\Exceptions\Engagement;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** An A13 abuse signal or pause that can no longer be acted on (decided / lifted meanwhile): 409. */
final class SignalUnavailable extends HttpException
{
    public static function decided(): self
    {
        return new self(409, __('This signal has already been decided.'));
    }

    public static function lifted(): self
    {
        return new self(409, __('This pause has already ended.'));
    }
}
