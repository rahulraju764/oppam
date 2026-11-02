<?php

declare(strict_types=1);

namespace App\Data\Content;

/** One success-story couple (x-story.card and the long-form section on /success-stories). */
final readonly class StoryData
{
    /** `bodyHtml` was sanitised when it was saved. */
    public function __construct(
        public string $slug,
        public string $couple,
        public string $place,
        public string $date,
        public string $imageUrl,
        public int $imageWidth,
        public int $imageHeight,
        public string $quote,
        public string $bodyHtml,
    ) {}

    /** The couple's anchor on the success-stories page (no per-couple page exists). */
    public function url(): string
    {
        return route('success-stories').'#story-'.$this->slug;
    }
}
