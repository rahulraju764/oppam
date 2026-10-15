<?php

declare(strict_types=1);

namespace App\Data\Admin;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * One work queue as the admin dashboard and sidebar show it (PRD A02 "work-queue cards with
 * count + oldest-item age"): how many items wait, how long the oldest has waited, where to go.
 * `key` is the queue key carried by AdminQueueCountChanged (null = no live count for it).
 */
final readonly class WorkQueueData
{
    public function __construct(
        public ?string $key,
        public string $label,
        public string $routeName,
        public string $icon,
        public int $count,
        public ?CarbonImmutable $oldest,
        public bool $breached = false,
    ) {}

    public function oldestAge(): ?string
    {
        return $this->oldest?->diffForHumans(['syntax' => CarbonInterface::DIFF_ABSOLUTE]);
    }
}
