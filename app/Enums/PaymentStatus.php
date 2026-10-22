<?php

declare(strict_types=1);

namespace App\Enums;

/** One gateway payment against an order (M10). Only CAPTURED money activates a plan. */
enum PaymentStatus: string
{
    case Captured = 'CAPTURED';
    case Failed = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::Captured => __('Captured'),
            self::Failed => __('Failed'),
        };
    }
}
