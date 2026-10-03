<?php

declare(strict_types=1);

namespace App\Enums;

/** How a subscription came about (A06: paid order, or a complimentary grant by an admin). */
enum SubscriptionSource: string
{
    case Paid = 'PAID';
    case Complimentary = 'COMPLIMENTARY';

    public function label(): string
    {
        return match ($this) {
            self::Paid => __('Paid'),
            self::Complimentary => __('Complimentary'),
        };
    }
}
