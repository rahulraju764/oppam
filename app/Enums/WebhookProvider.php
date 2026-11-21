<?php

declare(strict_types=1);

namespace App\Enums;

/** A12 system health: who sent an inbound webhook (routes/webhooks.php), by route name. */
enum WebhookProvider: string
{
    case Razorpay = 'RAZORPAY';
    case Msg91 = 'MSG91';
    case Mail = 'MAIL';

    public function label(): string
    {
        return match ($this) {
            self::Razorpay => __('Razorpay payments'),
            self::Msg91 => __('MSG91 delivery reports'),
            self::Mail => __('Mail bounces'),
        };
    }

    /** The provider a webhook route belongs to — null for a route that isn't a known webhook. */
    public static function forRoute(?string $routeName): ?self
    {
        return match ($routeName) {
            'webhooks.razorpay' => self::Razorpay,
            'webhooks.msg91.dlr' => self::Msg91,
            'webhooks.mail.bounce' => self::Mail,
            default => null,
        };
    }
}
