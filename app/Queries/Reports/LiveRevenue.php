<?php

declare(strict_types=1);

namespace App\Queries\Reports;

use App\Domain\Reports\ReportRange;
use App\Enums\OrderStatus;
use App\Enums\ReferralStatus;
use App\Enums\RefundStatus;
use App\Enums\SubscriptionSource;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The A09 revenue numbers that aren't daily sums (P9.1b; owner definitions 2026-11-13), cached 10
 * minutes: members with a paid plan running in the period (ARPU's divisor), lifetime value (all-time
 * net ÷ members who ever paid), the typical days from signing up to the first payment, and revenue
 * through each bureau. Net = paid orders − processed refunds of the paying payment (RevenueSummary).
 */
final class LiveRevenue
{
    public const CACHE_SECONDS = 600;

    public const TOP_BUREAUS = 15;

    /** Members (profiles) with a PAID plan running at any time in the period. */
    public function payingMembers(ReportRange $range): int
    {
        return (int) $this->cached('paying', $range, fn (): int => DB::table('subscriptions')
            ->where('source', SubscriptionSource::Paid->value)
            ->where('starts_at', '<', $range->utcTo())->where('ends_at', '>', $range->utcFrom())
            ->distinct()->count('profile_id'));
    }

    /** All-time net revenue ÷ members who ever paid, in paise; null before the first payment. */
    public function lifetimeValue(): ?int
    {
        return Cache::remember('reports:live:ltv', self::CACHE_SECONDS, function (): ?int {
            $paid = DB::table('orders')->where('status', OrderStatus::Paid->value);
            $payers = (clone $paid)->distinct()->count('profile_id');
            if ($payers === 0) {
                return null;
            }
            $refunded = (int) DB::table('refunds')->join('orders', 'orders.id', '=', 'refunds.order_id')
                ->where('refunds.status', RefundStatus::Processed->value)
                ->whereColumn('orders.paid_payment_id', 'refunds.payment_id')->sum('refunds.amount_paise');

            return intdiv(max(0, (int) (clone $paid)->sum('total_paise') - $refunded), $payers);
        });
    }

    /** Median whole days from signing up to the first payment, for members first paying in the period. */
    public function medianDaysToFirstPayment(ReportRange $range): ?int
    {
        return $this->cached('first-pay-days', $range, function () use ($range): ?int {
            $days = DB::table('orders')
                ->join('profiles', 'profiles.id', '=', 'orders.profile_id')
                ->join('users', 'users.id', '=', 'profiles.user_id')
                ->where('orders.status', OrderStatus::Paid->value)
                ->where('orders.paid_at', '>=', $range->utcFrom())->where('orders.paid_at', '<', $range->utcTo())
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('orders as earlier')
                    ->whereColumn('earlier.profile_id', 'orders.profile_id')->where('earlier.status', OrderStatus::Paid->value)
                    ->where(fn ($w) => $w->whereColumn('earlier.paid_at', '<', 'orders.paid_at')
                        ->orWhere(fn ($tie) => $tie->whereColumn('earlier.paid_at', '=', 'orders.paid_at')->whereColumn('earlier.id', '<', 'orders.id'))))
                ->selectRaw('users.created_at as signed_up, orders.paid_at as paid')
                ->get()
                ->map(fn (object $r): int => (int) CarbonImmutable::parse((string) $r->signed_up)->diffInDays(CarbonImmutable::parse((string) $r->paid)))
                ->sort()->values()->all();

            return $days === [] ? null : $days[intdiv(count($days), 2)];
        });
    }

    /**
     * Revenue through bureaus in the period: paid orders that earned a commission (EARNED / PAID).
     *
     * @return list<array{code: string, name: string, orders: int, paise: int}> largest first
     */
    public function byBureau(ReportRange $range): array
    {
        /** @var list<array{code: string, name: string, orders: int, paise: int}> */
        return $this->cached('bureaus', $range, fn (): array => DB::table('orders')
            ->join('broker_referrals', 'broker_referrals.order_id', '=', 'orders.id')
            ->join('brokers', 'brokers.id', '=', 'broker_referrals.broker_id')
            ->where('orders.status', OrderStatus::Paid->value)
            ->where('orders.paid_at', '>=', $range->utcFrom())->where('orders.paid_at', '<', $range->utcTo())
            ->whereIn('broker_referrals.status', [ReferralStatus::Earned->value, ReferralStatus::Paid->value])
            ->groupBy('brokers.code', 'brokers.bureau_name')
            ->selectRaw('brokers.code as code, brokers.bureau_name as name, COUNT(*) as orders, SUM(orders.total_paise) as paise')
            ->orderByDesc('paise')->limit(self::TOP_BUREAUS)->get()
            ->map(fn (object $r): array => ['code' => (string) $r->code, 'name' => (string) $r->name, 'orders' => (int) $r->orders, 'paise' => (int) $r->paise])
            ->values()->all());
    }

    /** @param Closure(): mixed $compute */
    private function cached(string $kind, ReportRange $range, Closure $compute): mixed
    {
        return Cache::remember('reports:revenue:'.$kind.':'.$range->from->toDateString().':'.$range->to->toDateString(), self::CACHE_SECONDS, $compute);
    }
}
