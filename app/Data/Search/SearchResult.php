<?php

declare(strict_types=1);

namespace App\Data\Search;

/** A search request's answer: the page asked for and, when asked for, the total count. */
final readonly class SearchResult
{
    public function __construct(
        public SearchPage $page,
        public ?int $total,
    ) {}
}
