<?php

declare(strict_types=1);

namespace App\Data\Engagement;

/**
 * What ToggleLike left behind: whether the member now likes the profile, whether that made (or
 * already was) a mutual like, and whether anything was written by this call.
 */
final readonly class LikeResult
{
    public function __construct(
        public bool $liked,
        public bool $mutual,
        public bool $changed,
    ) {}
}
