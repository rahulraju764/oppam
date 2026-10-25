<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a commission ledger row went VOID (B.10). A refund of the commissioned order (R-M13-5,
 * any processed refund — owner 2026-10-24); later sessions add admin voids (fraud signals, P7.8).
 */
enum ReferralVoidReason: string
{
    case Refund = 'REFUND';

    public function label(): string
    {
        return match ($this) {
            self::Refund => __('Refunded'),
        };
    }
}
