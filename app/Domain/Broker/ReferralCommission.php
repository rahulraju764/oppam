<?php

declare(strict_types=1);

namespace App\Domain\Broker;

use App\Models\Order;

/**
 * Commission maths (B.10, R-M13-4): the base is the order's pre-GST amount actually charged —
 * `taxable_paise`, i.e. the subtotal after any coupon (owner 2026-10-24) — times the bureau's
 * rate in basis points, rounded half-up to the paisa in integer arithmetic (no floats).
 */
final class ReferralCommission
{
    public static function base(Order $order): int
    {
        return $order->taxable_paise;
    }

    public static function amount(int $basePaise, int $bps): int
    {
        return intdiv($basePaise * $bps + 5_000, 10_000);
    }
}
