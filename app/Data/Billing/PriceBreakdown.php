<?php

declare(strict_types=1);

namespace App\Data\Billing;

use App\ValueObjects\Money;

/** The server-computed amounts of a checkout, all in paise (PriceCalculator). */
final readonly class PriceBreakdown
{
    public function __construct(
        public int $subtotalPaise,
        public int $discountPaise,
        public int $taxablePaise,
        public int $gstRateBps,
        public int $gstPaise,
        public int $totalPaise,
    ) {}

    public function subtotal(): Money
    {
        return Money::paise($this->subtotalPaise);
    }

    public function discount(): Money
    {
        return Money::paise($this->discountPaise);
    }

    public function taxable(): Money
    {
        return Money::paise($this->taxablePaise);
    }

    public function gst(): Money
    {
        return Money::paise($this->gstPaise);
    }

    public function total(): Money
    {
        return Money::paise($this->totalPaise);
    }

    /** "18" for 1800 bps, "12.5" for 1250. */
    public function gstRateLabel(): string
    {
        return rtrim(rtrim(number_format($this->gstRateBps / 100, 2, '.', ''), '0'), '.');
    }
}
