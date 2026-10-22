<?php

declare(strict_types=1);

use App\Domain\Billing\CouponEligibility;
use App\Enums\CouponProblem;
use App\Enums\PlanCode;
use App\Models\Coupon;
use Carbon\CarbonImmutable;

/*
| P5.1 — A06 coupon rules (CouponEligibility): validity window, plans, minimum order, total and
| per-member limits, first paid order only. Unknown / inactive / used-up codes read the same.
*/

it('A06: decides whether a coupon may be used', function (?array $coupon, int $used, bool $paidBefore, ?CouponProblem $problem): void {
    expect(app(CouponEligibility::class)->problem(
        $coupon === null ? null : Coupon::factory()->make($coupon),
        PlanCode::Gold,
        99_900,
        $used,
        $paidBefore,
        CarbonImmutable::parse('2026-10-21 10:00:00', 'UTC'),
    ))->toBe($problem);
})->with([
    'valid' => [[], 0, false, null],
    'unknown code' => [null, 0, false, CouponProblem::Invalid],
    'switched off' => [['is_active' => false], 0, false, CouponProblem::Invalid],
    'all redemptions used' => [['max_redemptions' => 5, 'redemptions_count' => 5], 0, false, CouponProblem::Invalid],
    'not started' => [['starts_at' => '2026-10-22 00:00:00'], 0, false, CouponProblem::NotStarted],
    'expired at the second' => [['ends_at' => '2026-10-21 10:00:00'], 0, false, CouponProblem::Expired],
    'other plans only' => [['plan_codes' => ['DIAMOND']], 0, false, CouponProblem::WrongPlan],
    'this plan listed' => [['plan_codes' => ['GOLD', 'DIAMOND']], 0, false, null],
    'below minimum' => [['min_order_paise' => 100_000], 0, false, CouponProblem::BelowMinimum],
    'member limit reached' => [[], 1, false, CouponProblem::AlreadyUsed],
    'member limit 3, used 2' => [['per_member_limit' => 3], 2, false, null],
    'first order only, paid before' => [['first_time_only' => true], 0, true, CouponProblem::FirstOrderOnly],
    'first order only, never paid' => [['first_time_only' => true], 0, false, null],
]);
