<?php

declare(strict_types=1);

namespace App\Domain\Comms\Journeys;

/**
 * A member who qualifies for one journey step (P8.3b2). subjectRef = what the step is about, so the
 * ledger never repeats it; actorProfileId = the other member involved (the block check), if any;
 * count = a number the message shows (new matches waiting), if any.
 */
final readonly class JourneyCandidate
{
    public function __construct(
        public string $userId,
        public string $subjectRef,
        public ?string $actorProfileId = null,
        public int $count = 0,
    ) {}
}
