<?php

declare(strict_types=1);

namespace App\Data\Billing;

use Carbon\CarbonImmutable;

/** The window a usage counter covers: [start, end) in UTC; end null = never resets (lifetime). */
final readonly class UsagePeriod
{
    public function __construct(
        public CarbonImmutable $start,
        public ?CarbonImmutable $end,
    ) {}
}
