<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A checkout order's state (M10, F04, A06). CREATED until the gateway's webhook confirms a captured
 * payment (→ PAID); an unpaid order passes its `expires_at` (→ EXPIRED, swept hourly) or is refused
 * by the gateway (→ FAILED). A payment captured on an EXPIRED order still activates — the money
 * was taken. A refunded order stays PAID: its refunds are rows of their own (A06, P5.3).
 */
enum OrderStatus: string
{
    case Created = 'CREATED';
    case Paid = 'PAID';
    case Failed = 'FAILED';
    case Expired = 'EXPIRED';

    public function label(): string
    {
        return match ($this) {
            self::Created => __('Awaiting payment'),
            self::Paid => __('Paid'),
            self::Failed => __('Failed'),
            self::Expired => __('Expired'),
        };
    }

    /** A captured payment may still activate this order. */
    public function isPayable(): bool
    {
        return $this === self::Created || $this === self::Expired;
    }
}
