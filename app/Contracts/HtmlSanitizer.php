<?php

declare(strict_types=1);

namespace App\Contracts;

/** Reduces admin-written rich text to a safe allow-list before it is stored (A08, CLAUDE.md rule 9). */
interface HtmlSanitizer
{
    public function clean(string $html): string;

    /**
     * A legal document: also clause headings (h2, h3 with an id for cross-references) and the
     * template's fill-in mark (span.legal-fill). Still no scripts, styles, images or handlers.
     */
    public function cleanDocument(string $html): string;
}
