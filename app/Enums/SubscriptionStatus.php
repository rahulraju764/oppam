<?php

declare(strict_types=1);

namespace App\Enums;

/** A membership subscription's state (M10, A06). Billing (P5) drives the transitions. */
enum SubscriptionStatus: string
{
    case Active = 'ACTIVE';
    case Expired = 'EXPIRED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Expired => __('Expired'),
            self::Cancelled => __('Cancelled'),
        };
    }
}
