<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * A checkout the member can't start right now, with a reason they can be shown (M10). `reason` is
 * a stable key for tests and the UI; the message is member-facing and never contains gateway text.
 */
final class CheckoutRefused extends RuntimeException
{
    public const ACCOUNT_NOT_ACTIVE = 'account_not_active';

    public const DOWNGRADE = 'downgrade';

    public const TOO_MANY = 'too_many';

    public const UNAVAILABLE = 'unavailable';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function accountNotActive(): self
    {
        return new self(self::ACCOUNT_NOT_ACTIVE, __('Memberships can’t be bought on this account right now. Please contact support.'));
    }

    /** Decisions 2026-10-21: no switching to a lower plan while a higher one runs. */
    public static function downgrade(string $currentPlan, string $until): self
    {
        return new self(self::DOWNGRADE, __('You are on :plan until :date. You can choose a lower plan after it ends.', ['plan' => $currentPlan, 'date' => $until]));
    }

    public static function tooMany(): self
    {
        return new self(self::TOO_MANY, __('Too many attempts. Please wait a while and try again.'));
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE, __('Payments are not available right now. Please try again in a few minutes.'));
    }
}
