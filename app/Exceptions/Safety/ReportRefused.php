<?php

declare(strict_types=1);

namespace App\Exceptions\Safety;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A report the member can't file right now (PRD M09), with a member-facing reason: too many
 * reports in an hour (429), or this member is already reported and under review (409).
 */
final class ReportRefused extends HttpException
{
    public const TOO_MANY = 'too_many';

    public const ALREADY_REPORTED = 'already_reported';

    private function __construct(public readonly string $reason, int $status, string $message)
    {
        parent::__construct($status, $message);
    }

    /** `chat.reports_max_per_hour` (CLAUDE.md rule 10). */
    public static function tooMany(): self
    {
        return new self(self::TOO_MANY, 429, __('You have sent several reports just now. Please try again in a while.'));
    }

    /** One open report per member you report: our team is already looking at it. */
    public static function alreadyReported(): self
    {
        return new self(self::ALREADY_REPORTED, 409, __('You have already reported this member. Our team is reviewing it.'));
    }
}
