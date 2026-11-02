<?php

declare(strict_types=1);

namespace App\Data\Content;

/** One slide of the home hero carousel. */
final readonly class BannerData
{
    public function __construct(
        public string $heading,
        public string $copy,
        public string $imageUrl,
        public int $imageWidth,
        public int $imageHeight,
        public string $alt = '',
        public ?string $ctaLabel = null,
        public ?string $ctaUrl = null,
    ) {}
}
