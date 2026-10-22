<?php

declare(strict_types=1);

use App\Domain\Billing\FinancialYear;
use App\Domain\Billing\PlanChangeRule;
use App\Enums\PlanChange;
use App\Models\Plan;
use Carbon\CarbonImmutable;

/*
| P5.1 — pure billing rules: the Indian financial year for invoice numbers (IST), and what buying a
| plan does to the current one (decisions 2026-10-21). Coupon eligibility: tests/Feature/Billing.
*/

it('M10 / A06: invoice numbers use the Indian financial year, which turns at 1 April 00:00 IST', function (string $utc, string $year): void {
    expect((new FinancialYear('Asia/Kolkata'))->of(CarbonImmutable::parse($utc, 'UTC')))->toBe($year);
})->with([
    'October 2026' => ['2026-10-21 10:00:00', '2627'],
    '31 Mar 23:59 IST' => ['2027-03-31 18:29:59', '2627'],
    '1 Apr 00:00 IST (still 31 Mar UTC)' => ['2027-03-31 18:30:00', '2728'],
    'January' => ['2027-01-15 00:00:00', '2627'],
    'century turn' => ['2099-06-01 00:00:00', '9900'],
]);

it('labels a financial year', function (): void {
    expect((new FinancialYear)->label('2627'))->toBe('2026-27');
});

it('decisions 2026-10-21: same plan extends, higher upgrades, lower is a downgrade', function (?int $current, int $target, bool $same, PlanChange $change): void {
    $plan = fn (int $rank, string $id): Plan => (new Plan)->forceFill(['id' => $id, 'sort_order' => $rank]);
    $currentPlan = $current === null ? null : $plan($current, $same ? 'same' : 'current');

    expect((new PlanChangeRule)->decide($currentPlan, $plan($target, $same ? 'same' : 'target')))->toBe($change);
})->with([
    'no current plan' => [null, 2, false, PlanChange::New],
    'same plan' => [2, 2, true, PlanChange::Extend],
    'Silver → Gold' => [1, 2, false, PlanChange::Upgrade],
    'Diamond → Silver' => [3, 1, false, PlanChange::Downgrade],
]);
