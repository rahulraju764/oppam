<?php

declare(strict_types=1);

namespace App\Data\Chat;

/**
 * A slice of a conversation shown on a break-glass read (PRD A13): messages oldest first, the
 * slice's first and last message ids (the viewer's audited range) and whether older ones exist.
 */
final readonly class BreakGlassPageData
{
    /** @param  list<ModeratedMessageData>  $messages */
    public function __construct(
        public array $messages,
        public ?string $oldestId,
        public ?string $newestId,
        public bool $hasEarlier,
    ) {}
}
