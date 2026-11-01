<?php

declare(strict_types=1);

namespace App\Data\Billing;

/** The A06 plan editor's card and prices. Prices are whole rupees before GST, by duration in months. */
final readonly class PlanEditData
{
    /**
     * @param  list<string>  $displayFeatures
     * @param  array<int, array{rupees: int, active: bool}>  $prices
     */
    public function __construct(
        public string $name,
        public ?string $badge,
        public bool $isFeatured,
        public bool $isPurchasable,
        public array $displayFeatures,
        public array $prices,
    ) {}
}
