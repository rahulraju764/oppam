<?php

declare(strict_types=1);

namespace App\Support\Navigation;

/** The resolved back / prev / next bar for one page (see config/pagenav.php). */
final readonly class PageNav
{
    public function __construct(
        public string $title,
        public NavLink $back,
        public ?NavLink $prev = null,
        public ?NavLink $next = null,
    ) {}
}
