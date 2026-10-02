<?php

declare(strict_types=1);

namespace App\Data\Moderation;

/** One automatic pre-flag on a moderation item (A04): a code for tests/filters and a human line. */
final readonly class ModerationFlag
{
    public function __construct(
        public string $code,
        public string $message,
    ) {}
}
