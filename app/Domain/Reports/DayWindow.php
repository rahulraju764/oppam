<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use Carbon\CarbonImmutable;

/** One IST day as a UTC [from, to) window for timestamp columns, plus its date string (A09, P9.1a). */
final readonly class DayWindow
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $date,
    ) {}

    public static function of(CarbonImmutable $day): self
    {
        $start = $day->setTimezone((string) config('oppam.display_timezone'))->startOfDay();

        return new self($start->utc(), $start->addDay()->utc(), $start->toDateString());
    }
}
