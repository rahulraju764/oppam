<?php

declare(strict_types=1);

namespace App\Queries\Admin;

use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\Refund;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A02 revenue tiles and chart (P3.5 hand-over): paid orders less processed refunds, GST included,
 * by IST day and month; revenue by plan over 30 days, net the same way. Grouped queries on indexed
 * date columns.
 */
final class RevenueSummary
{
    /** @return array{today: Money, month: Money} */
    public function summary(): array
    {
        $now = CarbonImmutable::now((string) config('oppam.display_timezone'));

        return [
            'today' => Money::paise(max(0, $this->netSince($now->startOfDay()))),
            'month' => Money::paise(max(0, $this->netSince($now->startOfMonth()))),
        ];
    }

    /**
     * Whole rupees by plan over the last 30 days, net the same way as the tiles: paid orders less the
     * refunds processed in the window of the payment that paid each order (an extra payment was never
     * counted). Plans that sold in the window; a plan's bar never goes below zero.
     *
     * @return array{labels: list<string>, values: list<int>}
     */
    public function byPlan(): array
    {
        $since = now()->subDays(30);
        $paid = DB::table('orders')->join('plans', 'plans.id', '=', 'orders.plan_id')
            ->where('orders.status', OrderStatus::Paid->value)->where('orders.paid_at', '>=', $since)
            ->groupBy('plans.name')->orderBy('plans.name')
            ->selectRaw('plans.name as name, sum(orders.total_paise) as paise')->get();
        $refunded = DB::table('refunds')->join('orders', 'orders.id', '=', 'refunds.order_id')->join('plans', 'plans.id', '=', 'orders.plan_id')
            ->where('refunds.status', RefundStatus::Processed->value)->where('refunds.processed_at', '>=', $since)
            ->whereColumn('orders.paid_payment_id', 'refunds.payment_id')
            ->groupBy('plans.name')
            ->selectRaw('plans.name as name, sum(refunds.amount_paise) as paise')->pluck('paise', 'name');

        return [
            'labels' => $paid->pluck('name')->map(fn (mixed $n): string => (string) $n)->values()->all(),
            'values' => $paid->map(fn (object $row): int => intdiv(max(0, (int) $row->paise - (int) ($refunded[$row->name] ?? 0)), 100))->values()->all(),
        ];
    }

    private function netSince(CarbonImmutable $from): int
    {
        $paid = (int) Order::query()->where('status', OrderStatus::Paid)->where('paid_at', '>=', $from->utc())->sum('total_paise');
        $refunded = (int) Refund::query()->where('status', RefundStatus::Processed)->where('processed_at', '>=', $from->utc())
            ->whereHas('order', fn ($q) => $q->whereColumn('orders.paid_payment_id', 'refunds.payment_id'))   // an extra payment was never counted
            ->sum('amount_paise');

        return $paid - $refunded;
    }
}
