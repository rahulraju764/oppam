<?php

declare(strict_types=1);

namespace App\Data\Content;

/** One real figure in the counters strip. `icon` is a bundled image path, or a Font Awesome class. */
final readonly class CounterData
{
    public function __construct(
        public string $icon,
        public int $value,
        public string $label,
    ) {}
}
