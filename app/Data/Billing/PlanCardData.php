<?php

declare(strict_types=1);

namespace App\Data\Billing;

use App\ValueObjects\Money;

/**
 * One pricing card (x-pricing.card) for one duration. It never carries a price the browser could
 * send back: checkout selects a plan KEY and a duration, and the server computes the amount
 * (PRD M10, CLAUDE.md "Money"). Prices are before GST.
 */
final readonly class PlanCardData
{
    /** @param list<string> $features */
    public function __construct(
        public string $key,
        public string $name,
        public int $durationMonths,
        public Money $price,
        public Money $perMonth,
        public ?int $savingsPercent,
        public array $features,
        public bool $isFeatured = false,
        public ?string $badge = null,
        public ?string $ctaUrl = null,
    ) {}
}
