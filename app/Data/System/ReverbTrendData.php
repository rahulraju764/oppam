<?php

declare(strict_types=1);

namespace App\Data\System;

/**
 * Live connections for the A12 "Reverb connections drop > 30 %" alert, from the per-minute
 * health_samples: the latest sample (null = the server didn't answer) and the highest of the
 * samples before it in the window (null = no earlier answered sample).
 */
final readonly class ReverbTrendData
{
    public function __construct(
        public ?int $current,
        public ?int $recentPeak,
    ) {}
}
