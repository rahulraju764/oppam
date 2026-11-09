<?php

declare(strict_types=1);

namespace App\Exceptions\Admin;

use RuntimeException;

/** An A14 action was refused (P8.3a); the message is safe to show the admin. */
final class CommsRefused extends RuntimeException
{
    public static function tooManyTests(): self
    {
        return new self(__('You’ve sent many test messages in the last hour. Please try again later.'));
    }

    public static function noTestPhone(): self
    {
        return new self(__('No test number is set (COMMS_TEST_PHONES), so SMS templates can only be previewed.'));
    }

    public static function noVersion(): self
    {
        return new self(__('That version doesn’t exist.'));
    }

    public static function notResendable(): self
    {
        return new self(__('Only a failed or bounced member notification can be sent again.'));
    }

    public static function memberGone(): self
    {
        return new self(__('This member can no longer receive messages (account closed, suspended or the channel switched off).'));
    }

    public static function blockedPair(): self
    {
        return new self(__('This message is about a member the recipient has blocked (or who blocked them) since — it can’t be sent again.'));
    }

    public static function announcementEnded(): self
    {
        return new self(__('This announcement has already ended.'));
    }
}
