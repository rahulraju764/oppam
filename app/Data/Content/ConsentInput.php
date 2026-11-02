<?php

declare(strict_types=1);

namespace App\Data\Content;

/** Consent as ticked in the A08 editor: story = both partners, testimonial = `first` only. */
final readonly class ConsentInput
{
    public function __construct(
        public bool $first,
        public bool $second = false,
        public string $note = '',
    ) {}
}
