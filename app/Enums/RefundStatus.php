<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * An A06 refund (P5.3). Every refund needs a second admin (decisions 2026-10-23): REQUESTED by one
 * admin with `billing.refund`, then approved by ANOTHER (→ PROCESSING: sent to the gateway, side
 * effects applied in one transaction once the gateway accepts it) or rejected / withdrawn. The
 * gateway confirms (→ PROCESSED) or reports a failure (→ FAILED, an "investigate" row for finance).
 */
enum RefundStatus: string
{
    case Requested = 'REQUESTED';
    case Processing = 'PROCESSING';
    case Processed = 'PROCESSED';
    case Failed = 'FAILED';
    case Rejected = 'REJECTED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Requested => __('Awaiting approval'),
            self::Processing => __('Processing'),
            self::Processed => __('Refunded'),
            self::Failed => __('Failed'),
            self::Rejected => __('Rejected'),
            self::Cancelled => __('Withdrawn'),
        };
    }

    /** <x-ui.badge> variant. */
    public function badge(): string
    {
        return match ($this) {
            self::Requested => 'warning',
            self::Processing => 'default',
            self::Processed => 'success',
            self::Failed => 'danger',
            self::Rejected, self::Cancelled => 'muted',
        };
    }

    /** Still holds part of the payment: no second refund of the same money can be asked for. */
    public function reservesAmount(): bool
    {
        return in_array($this, [self::Requested, self::Processing, self::Processed], true);
    }

    /** Waiting for someone (approver or gateway): one open refund per order at a time (A06). */
    public function isOpen(): bool
    {
        return $this === self::Requested || $this === self::Processing;
    }

    /** @return list<string> */
    public static function reserving(): array
    {
        return array_values(array_map(fn (self $s): string => $s->value, array_filter(self::cases(), fn (self $s): bool => $s->reservesAmount())));
    }
}
