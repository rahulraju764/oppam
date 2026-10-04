<?php

declare(strict_types=1);

namespace App\Data\Matching;

final readonly class MatchScoreData
{
    public function __construct(
        public int $totalScore,
        public int $preferenceFit,
        public int $reverseFit,
        public int $activityScore,
        public int $completenessScore,
    ) {}
}
