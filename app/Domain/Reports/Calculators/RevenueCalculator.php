<?php

declare(strict_types=1);

namespace App\Domain\Reports\Calculators;

use App\Data\Reports\SnapshotRow;
use App\Domain\Reports\DayWindow;
use App\Enums\OrderStatus;
use App\Enums\ReferralStatus;
use App\Enums\RefundStatus;
use App\Enums\ReportMetric;
use App\Enums\SubscriptionSource;
use Illuminate\Support\Facades\DB;

/**
 * A09 revenue numbers for one IST day (P9.1b), in paise, GST included:
 *  - paid orders (by plan and by the buyer's district) and refunds processed that day — only the
 *    refund of the payment that paid the order counts (the A02 rule, RevenueSummary);
 *  - members paying for the first time; paid orders that earned a bureau commission (EARNED / PAID);
 *  - paid plans that ran to their end that day (an upgrade or a refund cancels a plan — not an end)
 *    and those with no new paid plan starting within 30 days. Those two are only final 30 days on,
 *    so TakeReportSnapshot stores them only for days at least that old (ReportMetric::settlesAfterDays).
 */
final class RevenueCalculator
{
    /** @return list<SnapshotRow> */
    public function day(DayWindow $day): array
    {
        $paid = DB::table('orders')
            ->join('plans', 'plans.id', '=', 'orders.plan_id')
            ->leftJoin('profiles', 'profiles.id', '=', 'orders.profile_id')
            ->where('orders.status', OrderStatus::Paid->value)
            ->where('orders.paid_at', '>=', $day->from)->where('orders.paid_at', '<', $day->to)
            ->selectRaw('plans.code as plan, profiles.district_id as district, COUNT(*) as n, SUM(orders.total_paise) as paise')
            ->groupBy('plans.code', 'profiles.district_id')
            ->get();

        $rows = [
            new SnapshotRow(ReportMetric::RevenuePaidPaise, (int) $paid->sum('paise')),
            new SnapshotRow(ReportMetric::OrdersPaid, (int) $paid->sum('n')),
        ];
        foreach ($paid->groupBy('plan') as $plan => $group) {
            $rows[] = new SnapshotRow(ReportMetric::RevenuePaidPaise, (int) $group->sum('paise'), 'plan:'.$plan);
            $rows[] = new SnapshotRow(ReportMetric::OrdersPaid, (int) $group->sum('n'), 'plan:'.$plan);
        }
        foreach ($paid->groupBy(fn (object $r): string => (string) ($r->district ?? 'none')) as $district => $group) {
            $rows[] = new SnapshotRow(ReportMetric::RevenuePaidPaise, (int) $group->sum('paise'), 'district:'.$district);
        }

        $refunds = DB::table('refunds')
            ->join('orders', 'orders.id', '=', 'refunds.order_id')
            ->join('plans', 'plans.id', '=', 'orders.plan_id')
            ->where('refunds.status', RefundStatus::Processed->value)
            ->where('refunds.processed_at', '>=', $day->from)->where('refunds.processed_at', '<', $day->to)
            ->whereColumn('orders.paid_payment_id', 'refunds.payment_id')   // an extra payment was never counted as revenue
            ->selectRaw('plans.code as plan, SUM(refunds.amount_paise) as paise')
            ->groupBy('plans.code')
            ->get();
        $rows[] = new SnapshotRow(ReportMetric::RevenueRefundedPaise, (int) $refunds->sum('paise'));
        foreach ($refunds as $r) {
            $rows[] = new SnapshotRow(ReportMetric::RevenueRefundedPaise, (int) $r->paise, 'plan:'.$r->plan);
        }

        $rows[] = new SnapshotRow(ReportMetric::FirstPaidMembers, DB::table('orders')
            ->where('status', OrderStatus::Paid->value)
            ->where('paid_at', '>=', $day->from)->where('paid_at', '<', $day->to)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('orders as earlier')
                ->whereColumn('earlier.profile_id', 'orders.profile_id')->where('earlier.status', OrderStatus::Paid->value)
                ->where(fn ($w) => $w->whereColumn('earlier.paid_at', '<', 'orders.paid_at')
                    ->orWhere(fn ($tie) => $tie->whereColumn('earlier.paid_at', '=', 'orders.paid_at')->whereColumn('earlier.id', '<', 'orders.id'))))
            ->distinct()->count('profile_id'));

        $rows[] = new SnapshotRow(ReportMetric::BrokerAttributedPaise, (int) DB::table('orders')
            ->where('orders.status', OrderStatus::Paid->value)
            ->where('orders.paid_at', '>=', $day->from)->where('orders.paid_at', '<', $day->to)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('broker_referrals')->whereColumn('broker_referrals.order_id', 'orders.id')
                ->whereIn('broker_referrals.status', [ReferralStatus::Earned->value, ReferralStatus::Paid->value]))
            ->sum('orders.total_paise'));

        $ended = DB::table('subscriptions')
            ->where('source', SubscriptionSource::Paid->value)
            ->whereNull('cancelled_at')
            ->where('ends_at', '>=', $day->from)->where('ends_at', '<', $day->to);
        $rows[] = new SnapshotRow(ReportMetric::PaidPlansEnded, (clone $ended)->count());
        $rows[] = new SnapshotRow(ReportMetric::PaidPlansChurned, (clone $ended)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('subscriptions as next')
                ->whereColumn('next.profile_id', 'subscriptions.profile_id')
                ->whereColumn('next.id', '!=', 'subscriptions.id')
                ->where('next.source', SubscriptionSource::Paid->value)
                ->whereColumn('next.starts_at', '>', 'subscriptions.starts_at')
                ->whereRaw('next.starts_at <= DATE_ADD(subscriptions.ends_at, INTERVAL '.ReportMetric::CHURN_DAYS.' DAY)'))
            ->count());

        return $rows;
    }
}
