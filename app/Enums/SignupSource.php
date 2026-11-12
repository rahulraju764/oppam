<?php

declare(strict_types=1);

namespace App\Enums;

/** Where a member came from (A09 growth, P9.1a): stamped once at registration. */
enum SignupSource: string
{
    case Organic = 'ORGANIC';
    /** A bureau's referral code resolved (§11A B.5). */
    case Broker = 'BROKER';
    /** A marketing link with utm_* parameters (first touch, 30 days). */
    case Utm = 'UTM';

    public function label(): string
    {
        return match ($this) {
            self::Organic => __('Direct / organic'),
            self::Broker => __('Through a bureau'),
            self::Utm => __('Marketing link'),
        };
    }
}
