<?php

declare(strict_types=1);

namespace App\Contracts;

/** Reduces admin-written rich text to a safe allow-list before it is stored (A08, CLAUDE.md rule 9). */
interface HtmlSanitizer
{
    public function clean(string $html): string;
}
