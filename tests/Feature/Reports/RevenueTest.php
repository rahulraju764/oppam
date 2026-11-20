<?php

declare(strict_types=1);

use App\Actions\Reports\TakeReportSnapshot;
use App\Domain\Reports\ReportRange;
use App\Domain\Reports\Reports\RevenueReport;
use App\Enums\OrderStatus;
use App\Enums\ReferralStatus;
use App\Enums\RefundStatus;
use App\Enums\ReportMetric;
use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Models\BrokerReferral;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\Refund;
use App\Models\ReportSnapshot;
use App\Models\Subscription;
use App\Queries\Reports\LiveRevenue;
use Carbon\CarbonImmutable;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;

/*
| P9.1b — A09 Revenue (owner definitions 2026-11-13): net = paid − refunds of the payment that paid
| the order (GST included), by plan / district; first-time payers; bureau-attributed (EARNED / PAID
| only); churn = paid plans run to their end, not renewed within 30 days — stored only once settled;
| ARPU and LTV maths. Exact paise.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00', 'Asia/Kolkata'));
    $this->gold = Plan::query()->where('code', 'GOLD')->sole();
    $this->silver = Plan::query()->where('code', 'SILVER')->sole();
});

function revenueAt(string $at): CarbonImmutable
{
    return CarbonImmutable::parse($at, 'Asia/Kolkata')->utc();
}

/** A paid order (with its paying payment) at an IST moment. */
function revenueOrder(Plan $plan, int $paise, string $at, ?Profile $profile = null): Order
{
    $order = Order::factory()->paid()->create(['plan_id' => $plan->id, 'total_paise' => $paise, 'paid_at' => revenueAt($at),
        'profile_id' => ($profile ?? Profile::factory()->active()->create())->id]);
    $payment = Payment::factory()->create(['order_id' => $order->id, 'amount_paise' => $paise]);
    $order->forceFill(['paid_payment_id' => $payment->id])->save();

    return $order->refresh();
}

function revenueSnap(string $day): void
{
    app(TakeReportSnapshot::class)->handle(CarbonImmutable::parse($day, 'Asia/Kolkata'));
}

function revenueStored(ReportMetric $metric, string $day, string $dimension = ''): ?int
{
    $v = ReportSnapshot::query()->where('metric', $metric->value)->where('day', $day)->where('dimension', $dimension)->value('value');

    return $v === null ? null : (int) $v;
}

it('A09 revenue: paid by plan and district per IST day; refunds only of the paying payment, on the day processed', function (): void {
    $a = revenueOrder($this->gold, 117_882, '2026-11-19 10:00');
    revenueOrder($this->silver, 58_882, '2026-11-19 23:59');
    revenueOrder($this->gold, 117_882, '2026-11-20 00:00');   // the next IST day
    Refund::factory()->create(['order_id' => $a->id, 'payment_id' => $a->paid_payment_id, 'amount_paise' => 50_000, 'status' => RefundStatus::Processed, 'processed_at' => revenueAt('2026-11-19 15:00')]);
    $extra = Payment::factory()->create(['order_id' => $a->id, 'amount_paise' => 117_882]);   // a duplicate payment, refunded — never revenue
    Refund::factory()->create(['order_id' => $a->id, 'payment_id' => $extra->id, 'amount_paise' => 117_882, 'status' => RefundStatus::Processed, 'processed_at' => revenueAt('2026-11-19 16:00')]);

    revenueSnap('2026-11-19');

    expect(revenueStored(ReportMetric::RevenuePaidPaise, '2026-11-19'))->toBe(176_764)
        ->and(revenueStored(ReportMetric::RevenuePaidPaise, '2026-11-19', 'plan:GOLD'))->toBe(117_882)
        ->and(revenueStored(ReportMetric::OrdersPaid, '2026-11-19'))->toBe(2)
        ->and(revenueStored(ReportMetric::RevenueRefundedPaise, '2026-11-19'))->toBe(50_000)
        ->and(revenueStored(ReportMetric::RevenueRefundedPaise, '2026-11-19', 'plan:GOLD'))->toBe(50_000);
});

it('A09 revenue: a member paying for the first time counts once; a renewal is not a new payer', function (): void {
    $member = Profile::factory()->active()->create();
    revenueOrder($this->gold, 117_882, '2026-10-01 10:00', $member);
    revenueOrder($this->gold, 117_882, '2026-11-19 10:00', $member);   // a renewal
    $new = Profile::factory()->active()->create();
    revenueOrder($this->silver, 58_882, '2026-11-19 11:00', $new);
    revenueOrder($this->silver, 58_882, '2026-11-19 12:00', $new);     // same member, same day

    revenueSnap('2026-11-19');

    expect(revenueStored(ReportMetric::FirstPaidMembers, '2026-11-19'))->toBe(1);
});

it('A09 revenue: bureau-attributed = paid orders with an EARNED or PAID commission (pending / void left out)', function (): void {
    foreach ([ReferralStatus::Earned, ReferralStatus::Paid, ReferralStatus::Pending, ReferralStatus::Void] as $i => $status) {
        $order = revenueOrder($this->silver, 10_000 * ($i + 1), '2026-11-19 10:00');
        BrokerReferral::factory()->create(['profile_id' => $order->profile_id, 'order_id' => $order->id, 'status' => $status]);
    }

    revenueSnap('2026-11-19');

    expect(revenueStored(ReportMetric::BrokerAttributedPaise, '2026-11-19'))->toBe(30_000);   // 10,000 + 20,000
});

it('A09 churn: a paid plan that ran to its end is churned unless a new paid plan starts within 30 days; stored only once settled', function (): void {
    $plan = fn (Profile $p, string $from, string $to, array $extra = []) => Subscription::factory()->create([
        'profile_id' => $p->id, 'plan_id' => $this->gold->id, 'source' => SubscriptionSource::Paid, 'status' => SubscriptionStatus::Expired,
        'starts_at' => revenueAt($from), 'ends_at' => revenueAt($to), ...$extra]);
    $left = Profile::factory()->active()->create();
    $plan($left, '2026-09-19 10:00', '2026-10-19 10:00');
    $renewed = Profile::factory()->active()->create();
    $plan($renewed, '2026-09-19 10:00', '2026-10-19 10:00');
    $plan($renewed, '2026-11-10 10:00', '2026-12-10 10:00');   // 22 days later: renewed
    $upgraded = Profile::factory()->active()->create();
    $plan($upgraded, '2026-09-19 10:00', '2026-10-19 10:00', ['cancelled_at' => revenueAt('2026-10-19 10:00')]);   // replaced: not an end
    Subscription::factory()->create(['profile_id' => Profile::factory()->active()->create()->id, 'plan_id' => $this->gold->id,
        'source' => SubscriptionSource::Complimentary, 'starts_at' => revenueAt('2026-09-19 10:00'), 'ends_at' => revenueAt('2026-10-19 10:00')]);   // not paid

    revenueSnap('2026-10-19');   // 32 days ago: settled
    expect(revenueStored(ReportMetric::PaidPlansEnded, '2026-10-19'))->toBe(2)
        ->and(revenueStored(ReportMetric::PaidPlansChurned, '2026-10-19'))->toBe(1);

    $plan(Profile::factory()->active()->create(), '2026-10-01 10:00', '2026-11-01 10:00');
    revenueSnap('2026-11-01');   // 19 days ago: not settled — no row, not a wrong zero
    expect(revenueStored(ReportMetric::PaidPlansEnded, '2026-11-01'))->toBeNull();
});

it('A09 the nightly command also settles the day 31 days back', function (): void {
    $this->artisan('reports:snapshot')->assertSuccessful()->expectsOutputToContain('Settled 2026-10-20');
    expect(ReportSnapshot::query()->where('day', '2026-10-20')->where('metric', 'paid_plans_ended')->exists())->toBeTrue();
});

it('A09 ARPU, LTV and free → paid: net ÷ members with a paid plan running; all-time net ÷ everyone who ever paid', function (): void {
    $a = Profile::factory()->active()->create();
    $b = Profile::factory()->active()->create();
    revenueOrder($this->gold, 100_000, '2026-11-10 10:00', $a);
    $o = revenueOrder($this->gold, 60_000, '2026-11-11 10:00', $b);
    Refund::factory()->create(['order_id' => $o->id, 'payment_id' => $o->paid_payment_id, 'amount_paise' => 20_000, 'status' => RefundStatus::Processed, 'processed_at' => revenueAt('2026-11-12 10:00')]);
    foreach ([$a, $b] as $p) {
        Subscription::factory()->create(['profile_id' => $p->id, 'plan_id' => $this->gold->id, 'source' => SubscriptionSource::Paid,
            'starts_at' => revenueAt('2026-11-10 10:00'), 'ends_at' => revenueAt('2026-12-10 10:00')]);
    }
    foreach (['2026-11-10', '2026-11-11', '2026-11-12'] as $d) {
        revenueSnap($d);
    }
    $range = ReportRange::of('2026-11-01', '2026-11-19');

    expect(app(LiveRevenue::class)->payingMembers($range))->toBe(2)
        ->and(app(LiveRevenue::class)->lifetimeValue())->toBe(70_000);   // (160,000 − 20,000) ÷ 2

    $data = app(RevenueReport::class)->build($range, false);
    $stats = collect($data->stats)->keyBy('label');
    expect($stats['Net revenue']->value)->toContain('1,400')
        ->and($stats['ARPU']->value)->toContain('700')
        ->and($stats['Lifetime value']->value)->toContain('700');
});

it('A09 revenue report: tables by plan, month, district and bureau; a failed order is never revenue', function (): void {
    revenueOrder($this->gold, 117_882, '2026-11-15 10:00');
    Order::factory()->create(['plan_id' => $this->gold->id, 'status' => OrderStatus::Failed, 'total_paise' => 999_999]);
    revenueSnap('2026-11-15');

    $tables = collect(app(RevenueReport::class)->build(ReportRange::of('2026-11-01', '2026-11-19'), false)->tables)->keyBy('key');

    expect($tables->keys()->all())->toBe(['revenue_by_plan', 'revenue_by_month', 'revenue_by_district', 'revenue_by_bureau'])
        ->and($tables['revenue_by_plan']->rows[0][1])->toBe(1)
        ->and(json_encode($tables['revenue_by_month']->rows))->toContain('2026-11');
});

it('A09 median days to first payment: two paid orders in the same second count as one first payment', function (): void {
    $a = Profile::factory()->active()->create();
    $b = Profile::factory()->active()->create();
    $a->user->forceFill(['created_at' => revenueAt('2026-11-08 10:00')])->save();   // 2 days before paying
    $b->user->forceFill(['created_at' => revenueAt('2026-11-01 10:00')])->save();   // 10 days before paying
    revenueOrder($this->gold, 100_000, '2026-11-10 10:00', $a);
    revenueOrder($this->silver, 50_000, '2026-11-10 10:00', $a);                      // same second, same member
    revenueOrder($this->gold, 100_000, '2026-11-11 10:00', $b);

    // Counted once: [2, 10] → 10. Counting A twice would give [2, 2, 10] → 2.
    expect(app(LiveRevenue::class)->medianDaysToFirstPayment(ReportRange::of('2026-11-01', '2026-11-19')))->toBe(10);
});
