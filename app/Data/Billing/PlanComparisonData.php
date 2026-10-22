<?php

declare(strict_types=1);

namespace App\Data\Billing;

/**
 * The plans page comparison table (M10 "feature comparison table (from plan_features)"). A cell is
 * true (included), false (not included) or a limit to print ("10", "Unlimited").
 */
final readonly class PlanComparisonData
{
    /**
     * @param  list<string>  $plans  column headings, cheapest first
     * @param  list<array{label: string, cells: list<bool|string>}>  $rows
     */
    public function __construct(
        public array $plans,
        public ?int $featuredColumn,
        public array $rows,
    ) {}
}
