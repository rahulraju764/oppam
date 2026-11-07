<?php

declare(strict_types=1);

namespace App\Data\Content;

/** One block of a version comparison: unchanged, added in the newer version, or removed from it. */
final readonly class DiffLineData
{
    public const SAME = 'same';

    public const ADDED = 'added';

    public const REMOVED = 'removed';

    public function __construct(
        public string $type,
        public string $text,
    ) {}
}
