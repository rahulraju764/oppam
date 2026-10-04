<?php

declare(strict_types=1);

namespace App\Data\Search;

use App\Models\Profile;
use Illuminate\Support\Collection;

/** One page of search results and the cursor for the next page (null = this was the last). */
final readonly class SearchPage
{
    /** @param  Collection<int, Profile>  $profiles */
    public function __construct(
        public Collection $profiles,
        public ?string $nextCursor,
    ) {}
}
