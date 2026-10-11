<?php

declare(strict_types=1);

namespace App\Enums;

/** What a member asks to see (PRD M06 "Photo / Horoscope request"). */
enum AccessRequestType: string
{
    case Photo = 'PHOTO';
    case Horoscope = 'HOROSCOPE';

    public function label(): string
    {
        return match ($this) {
            self::Photo => __('Photos'),
            self::Horoscope => __('Horoscope'),
        };
    }

    public function notificationEvent(): NotificationEvent
    {
        return match ($this) {
            self::Photo => NotificationEvent::PhotoRequest,
            self::Horoscope => NotificationEvent::HoroscopeRequest,
        };
    }
}
