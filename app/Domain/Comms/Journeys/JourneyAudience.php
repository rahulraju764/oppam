<?php

declare(strict_types=1);

namespace App\Domain\Comms\Journeys;

use App\Enums\JourneyKey;
use Carbon\CarbonImmutable;

/**
 * Who qualifies for a journey step today (P8.3b2). The rule is code; the offsets and the channel are
 * configuration (automated_journeys). An audience returns candidates whose moment (sign-up, interest
 * sent, plan end, last activity) falls in the step's window (JourneyWindow); the ledger and the
 * per-member checks in RunJourneys decide who actually gets it.
 */
interface JourneyAudience
{
    public function key(): JourneyKey;

    /** @return iterable<JourneyCandidate> */
    public function candidates(int $offset, CarbonImmutable $now): iterable;
}
