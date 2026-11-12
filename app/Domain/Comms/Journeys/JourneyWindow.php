<?php

declare(strict_types=1);

namespace App\Domain\Comms\Journeys;

use Carbon\CarbonImmutable;

/**
 * The window a journey step looks at (P8.3b2), in IST days: a moment `offset` days ago, or one day
 * earlier — so a run missed for a day (deploy, outage) still sends the next day. The ledger keeps
 * a member from getting the same step twice across the two days. Returned as UTC instants for
 * the timestamp columns.
 */
final readonly class JourneyWindow
{
    private function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $until,
    ) {}

    public static function for(int $offset, CarbonImmutable $now): self
    {
        $today = $now->setTimezone((string) config('oppam.display_timezone'))->startOfDay();

        return new self(
            from: $today->subDays($offset + 1)->utc(),
            until: $today->subDays($offset - 1)->utc(),
        );
    }
}
