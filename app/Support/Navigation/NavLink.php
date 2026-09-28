<?php

declare(strict_types=1);

namespace App\Support\Navigation;

/** One resolved navigation link (display data only). */
final readonly class NavLink
{
    public function __construct(
        public string $url,
        public string $label,
        public ?string $icon = null,
        public ?string $description = null,
        public bool $active = false,
    ) {}
}
