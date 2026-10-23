<?php

declare(strict_types=1);

namespace App\Exceptions\Safety;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A block / ignore / support request the member can't make right now, with a member-facing
 * reason: too many in an hour (429, CLAUDE.md rule 10), or a ticket that is closed (409).
 */
final class SafetyRefused extends HttpException
{
    public const TOO_MANY = 'too_many';

    public const TICKET_CLOSED = 'ticket_closed';

    private function __construct(public readonly string $reason, int $status, string $message)
    {
        parent::__construct($status, $message);
    }

    public static function tooMany(): self
    {
        return new self(self::TOO_MANY, 429, __('You have done this several times just now. Please try again in a while.'));
    }

    public static function ticketClosed(): self
    {
        return new self(self::TICKET_CLOSED, 409, __('This request is closed. Please open a new one if you still need help.'));
    }
}
