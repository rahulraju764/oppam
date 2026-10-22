<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Enums\PlanChange;
use App\Models\Plan;

/**
 * What buying `$target` does while `$current` runs (decisions 2026-10-21). Plans rank by
 * `sort_order` (Free < Silver < Gold < Diamond, editable in A06). Pure.
 */
final class PlanChangeRule
{
    public function decide(?Plan $current, Plan $target): PlanChange
    {
        return match (true) {
            $current === null => PlanChange::New,
            $current->id === $target->id => PlanChange::Extend,
            $target->sort_order > $current->sort_order => PlanChange::Upgrade,
            default => PlanChange::Downgrade,
        };
    }
}
