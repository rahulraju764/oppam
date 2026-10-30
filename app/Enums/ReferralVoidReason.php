<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a commission ledger row went VOID (B.10). A refund of the commissioned order (R-M13-5,
 * any processed refund — owner 2026-10-24), or an A07 "void all" after a fraud signal (P7.8c).
 */
enum ReferralVoidReason: string
{
    case Refund = 'REFUND';
    case Fraud = 'FRAUD';   // A07 "void all" after a fraud signal (B.12 "broker referring themselves → suspend, void all")

    public function label(): string
    {
        return match ($this) {
            self::Refund => __('Refunded'),
            self::Fraud => __('Voided after a review'),
        };
    }
}
