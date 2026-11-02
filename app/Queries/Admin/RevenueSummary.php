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
 * by IST day and month; revenue by plan over 30 days. Grouped queries on indexed date columns.
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

    /** @return array{labels: list<string>, values: list<int>} whole rupees by plan, last 30 days */
    public function byPlan(): array
    {
        $rows = DB::table('orders')->join('plans', 'plans.id', '=', 'orders.plan_id')
            ->where('orders.status', OrderStatus::Paid->value)->where('orders.paid_at', '>=', now()->subDays(30))
            ->groupBy('plans.name')->orderBy('plans.name')
            ->selectRaw('plans.name as name, sum(orders.total_paise) as paise')->get();

        return [
            'labels' => $rows->pluck('name')->map(fn (mixed $n): string => (string) $n)->values()->all(),
            'values' => $rows->pluck('paise')->map(fn (mixed $p): int => intdiv((int) $p, 100))->values()->all(),
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
