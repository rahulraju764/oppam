<?php

declare(strict_types=1);

namespace App\Data\Billing;

use Carbon\CarbonImmutable;

/**
 * One usage meter on /billing (M10 "usage meters (interests left, contact views left)").
 * limit null = unlimited, 0 = not in this plan; resetsAt null = a running total (favourites).
 */
final readonly class UsageMeterData
{
    public function __construct(
        public string $label,
        public int $used,
        public ?int $limit,
        public ?CarbonImmutable $resetsAt,
    ) {}

    public function remaining(): ?int
    {
        return $this->limit === null ? null : max(0, $this->limit - $this->used);
    }

    /** 0–100 for the bar; 0 when unlimited or not included. */
    public function percent(): int
    {
        return $this->limit === null || $this->limit === 0 ? 0 : (int) min(100, round($this->used * 100 / $this->limit));
    }
}
