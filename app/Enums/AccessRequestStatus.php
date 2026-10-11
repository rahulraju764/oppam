<?php

declare(strict_types=1);

namespace App\Enums;

/** A photo / horoscope request: PENDING → APPROVED | DECLINED (by the owner). */
enum AccessRequestStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Declined = 'DECLINED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Awaiting reply'),
            self::Approved => __('Approved'),
            self::Declined => __('Declined'),
        };
    }
}
