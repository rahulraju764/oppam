<?php

declare(strict_types=1);

namespace App\Exceptions\Chat;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** A flagged message that can't be decided now (A13): already decided, or escalated to a super admin (409). */
final class MessageFlagUnavailable extends HttpException
{
    public static function decided(): self
    {
        return new self(409, __('This flag has already been decided.'));
    }

    public static function escalated(): self
    {
        return new self(409, __('Only a super admin can decide an escalated flag.'));
    }
}
