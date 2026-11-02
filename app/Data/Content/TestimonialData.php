<?php

declare(strict_types=1);

namespace App\Data\Content;

/** One quote on the home page. */
final readonly class TestimonialData
{
    public function __construct(
        public string $name,
        public string $place,
        public string $quote,
        public string $imageUrl,
        public int $imageWidth,
        public int $imageHeight,
    ) {}
}
