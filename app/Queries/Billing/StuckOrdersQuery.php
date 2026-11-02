<?php

declare(strict_types=1);

namespace App\Queries\Billing;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/**
 * A06 "stuck orders": not paid, yet captured money is still held (nothing has refunded it), or still
 * open after its expiry (the webhook may be lost and the expiry job hasn't run).
 */
final class StuckOrdersQuery
{
    /** @return Builder<Order> */
    public function query(): Builder
    {
        $reserving = implode(', ', array_fill(0, count(RefundStatus::reserving()), '?'));

        return Order::query()->where('orders.status', '!=', OrderStatus::Paid->value)
            ->where(fn (Builder $q) => $q
                ->whereHas('payments', fn (Builder $p) => $p->where('status', PaymentStatus::Captured->value)
                    ->whereRaw('payments.amount_paise > (select coalesce(sum(r.amount_paise), 0) from refunds r where r.payment_id = payments.id and r.status in ('.$reserving.'))', RefundStatus::reserving()))
                ->orWhere(fn (Builder $open) => $open->where('orders.status', OrderStatus::Created->value)->where('orders.expires_at', '<', now())));
    }
}
