<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Interest state machine (PRD M06 / F02): PENDING → ACCEPTED | DECLINED | WITHDRAWN (by the sender)
 * | EXPIRED (after `interest.expiry_days`). Only PENDING can change; every other state is final
 * for that send (a later re-send starts a new PENDING on the same row).
 */
enum InterestStatus: string
{
    case Pending = 'PENDING';
    case Accepted = 'ACCEPTED';
    case Declined = 'DECLINED';
    case Withdrawn = 'WITHDRAWN';
    case Expired = 'EXPIRED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Awaiting reply'),
            self::Accepted => __('Accepted'),
            self::Declined => __('Declined'),
            self::Withdrawn => __('Withdrawn'),
            self::Expired => __('Expired'),
        };
    }

    /** <x-ui.badge> variant (explicit map — never built from data). */
    public function badge(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Accepted => 'success',
            self::Declined, self::Withdrawn, self::Expired => 'muted',
        };
    }

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}
