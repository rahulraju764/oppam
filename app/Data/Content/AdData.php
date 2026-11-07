<?php

declare(strict_types=1);

namespace App\Data\Content;

/** One rail creative as the view needs it. External links open in a new tab, marked sponsored. */
final readonly class AdData
{
    public function __construct(
        public string $heading,
        public ?string $copy,
        public string $ctaLabel,
        public string $ctaUrl,
        public bool $external,
        public ?string $imageUrl,
        public int $imageWidth,
        public int $imageHeight,
        public string $alt,
    ) {}
}
