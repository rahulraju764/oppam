<?php

declare(strict_types=1);

namespace App\Data\Matching;

/** One line of "You match X of Y preferences" (M03): what they asked for, and whether you fit. */
final readonly class PreferenceCheck
{
    public function __construct(
        public string $label,
        public string $wanted,
        public bool $matches,
    ) {}
}
