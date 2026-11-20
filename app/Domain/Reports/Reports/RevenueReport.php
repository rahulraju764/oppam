<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Data\Reports\ReportData;
use App\Data\Reports\ReportStatData;
use App\Data\Reports\ReportTableData;
use App\Domain\Reports\ReportRange;
use App\Enums\PlanCode;
use App\Enums\ReportMetric;
use App\Models\AdminUser;
use App\Queries\Reports\LiveRevenue;
use App\Queries\Reports\ReportSeries;
use App\ValueObjects\Money;
use Generator;

/**
 * A09 Revenue (P9.1b; owner definitions 2026-11-13): net revenue (paid − refunds of the paying
 * payment, GST included), orders, refunds, ARPU (net ÷ members with a paid plan running in the
 * period), LTV (all-time net ÷ members who ever paid), churn (paid plans that ran to their end and
 * weren't renewed within 30 days — settled 30 days on), free → paid, bureau-attributed revenue;
 * by plan, month, district and bureau.
 */
final class RevenueReport implements ReportDefinition
{
    use FormatsReportNumbers;

    public const TOP_DISTRICTS = 15;

    private const FLOWS = [
        ReportMetric::RevenuePaidPaise, ReportMetric::RevenueRefundedPaise, ReportMetric::OrdersPaid,
        ReportMetric::FirstPaidMembers, ReportMetric::BrokerAttributedPaise, ReportMetric::PaidPlansEnded,
        ReportMetric::PaidPlansChurned, ReportMetric::Signups,
    ];

    public function __construct(
        private readonly ReportSeries $series,
        private readonly LiveRevenue $live,
    ) {}

    public function build(ReportRange $range, bool $compare, ?AdminUser $viewer = null): ReportData
    {
        $t = $this->series->totals($range, self::FLOWS);
        $b = $compare ? $this->series->totals($range->previous(), self::FLOWS) : null;
        $net = fn (array $x): int => $x['revenue_paid_paise'] - $x['revenue_refunded_paise'];
        $paying = $this->live->payingMembers($range);
        $arpu = $paying > 0 ? intdiv(max(0, $net($t)), $paying) : null;
        $churn = self::pct($t['paid_plans_churned'], $t['paid_plans_ended']);
        $ltv = $this->live->lifetimeValue();
        $days = $this->live->medianDaysToFirstPayment($range);

        $stats = [
            new ReportStatData(__('Net revenue'), self::money($net($t)), __('Paid orders less refunds of the payment that paid them, GST included.'),
                $b ? ReportStatData::delta($net($t), $net($b)) : null),
            new ReportStatData(__('Paid orders'), self::n($t['orders_paid']), ReportMetric::OrdersPaid->definition(), $b ? ReportStatData::delta($t['orders_paid'], $b['orders_paid']) : null),
            new ReportStatData(__('Refunded'), self::money($t['revenue_refunded_paise']), ReportMetric::RevenueRefundedPaise->definition()),
            new ReportStatData(__('ARPU'), $arpu === null ? '—' : self::money($arpu), __('Net revenue ÷ members with a paid plan running in the period.')),
            new ReportStatData(__('Lifetime value'), $ltv === null ? '—' : self::money($ltv), __('All-time net revenue ÷ members who ever paid.')),
            new ReportStatData(__('Churn'), self::p($churn), __('Paid plans that ran to their end in the period and were not renewed within 30 days. Days in the last 30 settle later.'),
                $b ? ReportStatData::delta($churn, self::pct($b['paid_plans_churned'], $b['paid_plans_ended']), true) : null),
            new ReportStatData(__('Free → paid'), self::p(self::pct($t['first_paid_members'], $t['signups'])),
                __('Members paying for the first time ÷ signups in the period:days.', ['days' => $days === null ? '' : ' '.__('(typically :n days after signing up)', ['n' => $days])])),
            new ReportStatData(__('Through bureaus'), self::p(self::pct($t['broker_attributed_paise'], $t['revenue_paid_paise'])), ReportMetric::BrokerAttributedPaise->definition()),
        ];

        $daily = $this->series->daily($range, [ReportMetric::RevenuePaidPaise, ReportMetric::RevenueRefundedPaise]);
        $byPlan = $this->planRows($range);
        $charts = [
            ['id' => 'revenue-daily', 'title' => __('Net revenue per day (₹)'), 'type' => 'bar', 'labels' => $daily['labels'], 'datasets' => [
                ['label' => __('Net'), 'data' => array_map(fn (int $p, int $r): int => intdiv($p - $r, 100), $daily['series']['revenue_paid_paise'], $daily['series']['revenue_refunded_paise'])],
            ]],
            ['id' => 'revenue-plan', 'title' => __('Net revenue by plan (₹)'), 'type' => 'doughnut',
                'labels' => array_column($byPlan, 'label'),
                'datasets' => [['label' => __('Net'), 'data' => array_map(fn (array $r): int => intdiv(max(0, $r['net']), 100), $byPlan)]]],
        ];

        return new ReportData($stats, $charts, iterator_to_array($this->tables($range), false), [
            __('Amounts include GST. A refund counts on the day it was processed; one for a payment that never paid an order is left out.'),
            __('Churn for the last 30 days of a period is filled in once those plans have had 30 days to renew.'),
        ]);
    }

    public function exportTables(ReportRange $range, ?AdminUser $viewer = null): iterable
    {
        return $this->tables($range);
    }

    public function exportRows(ReportRange $range): int
    {
        return array_sum(array_map(fn (ReportTableData $t): int => count($t->rows), iterator_to_array($this->tables($range), false)));
    }

    /** @return Generator<int, ReportTableData> */
    private function tables(ReportRange $range): Generator
    {
        $rows = [];
        foreach ($this->planRows($range) as $plan) {
            $rows[] = [$plan['label'], $plan['orders'], self::money($plan['paid']), self::money($plan['refunded']), self::money($plan['net'])];
        }
        yield new ReportTableData('revenue_by_plan', __('Revenue by plan'), [__('Plan'), __('Orders'), __('Paid'), __('Refunded'), __('Net')], $rows);

        $daily = $this->series->daily($range, [ReportMetric::RevenuePaidPaise, ReportMetric::RevenueRefundedPaise, ReportMetric::OrdersPaid]);
        $months = [];
        foreach ($range->dates() as $i => $date) {
            $m = substr($date, 0, 7);
            $months[$m] ??= ['orders' => 0, 'paid' => 0, 'refunded' => 0];
            $months[$m]['orders'] += $daily['series']['orders_paid'][$i];
            $months[$m]['paid'] += $daily['series']['revenue_paid_paise'][$i];
            $months[$m]['refunded'] += $daily['series']['revenue_refunded_paise'][$i];
        }
        $rows = [];
        foreach ($months as $month => $v) {
            $rows[] = [$month, $v['orders'], self::money($v['paid']), self::money($v['refunded']), self::money($v['paid'] - $v['refunded'])];
        }
        yield new ReportTableData('revenue_by_month', __('Revenue by month'), [__('Month'), __('Orders'), __('Paid'), __('Refunded'), __('Net')], $rows);

        $byDistrict = $this->series->byDimension($range, ReportMetric::RevenuePaidPaise, 'district');
        $labels = self::districtLabels(array_map('strval', array_keys($byDistrict)));
        $rows = [];
        $other = 0;
        foreach ($byDistrict as $district => $paise) {
            if (count($rows) < self::TOP_DISTRICTS) {
                $rows[] = [$labels[(string) $district], self::money($paise)];
            } else {
                $other += $paise;
            }
        }
        if ($other > 0) {
            $rows[] = [__('Other districts'), self::money($other)];
        }
        yield new ReportTableData('revenue_by_district', __('Paid by the buyer\'s district'), [__('District'), __('Paid')], $rows);

        $rows = [];
        foreach ($this->live->byBureau($range) as $bureau) {
            $rows[] = [$bureau['code'], $bureau['name'], $bureau['orders'], self::money($bureau['paise'])];
        }
        yield new ReportTableData('revenue_by_bureau', __('Revenue through bureaus'), [__('Bureau'), __('Name'), __('Orders'), __('Paid')], $rows);
    }

    /** @return list<array{label: string, orders: int, paid: int, refunded: int, net: int}> */
    private function planRows(ReportRange $range): array
    {
        $paid = $this->series->byDimension($range, ReportMetric::RevenuePaidPaise, 'plan');
        $refunded = $this->series->byDimension($range, ReportMetric::RevenueRefundedPaise, 'plan');
        $orders = $this->series->byDimension($range, ReportMetric::OrdersPaid, 'plan');

        $rows = [];
        foreach (array_unique([...array_keys($paid), ...array_keys($refunded)]) as $plan) {
            $p = $paid[$plan] ?? 0;
            $r = $refunded[$plan] ?? 0;
            $rows[] = ['label' => PlanCode::tryFrom((string) $plan)?->label() ?? (string) $plan, 'orders' => $orders[$plan] ?? 0, 'paid' => $p, 'refunded' => $r, 'net' => $p - $r];
        }

        return $rows;
    }

    private static function money(int $paise): string
    {
        return ($paise < 0 ? '−' : '').Money::paise(abs($paise))->formatWithSymbol();
    }
}
