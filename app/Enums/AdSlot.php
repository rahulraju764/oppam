<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The places in the template's right rail (ads.php → x-ads.rail): a text promo card, one image
 * banner, and the image carousel. Promos need no image; the other two do.
 */
enum AdSlot: string
{
    case Promo = 'PROMO';
    case Banner = 'BANNER';
    case Carousel = 'CAROUSEL';

    public function label(): string
    {
        return match ($this) {
            self::Promo => __('Text promo card'),
            self::Banner => __('Image banner'),
            self::Carousel => __('Carousel slide'),
        };
    }

    public function needsImage(): bool
    {
        return $this !== self::Promo;
    }
}
