<?php

declare(strict_types=1);

namespace App\Queries\Billing;

use App\Actions\Billing\ActivateSubscription;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/**
 * A06 "stuck orders": not paid, yet money was captured or the amount didn't match, or still open
 * after its expiry (the webhook may be lost and the expiry job hasn't run).
 */
final class StuckOrdersQuery
{
    /** @return Builder<Order> */
    public function query(): Builder
    {
        return Order::query()->where('orders.status', '!=', OrderStatus::Paid->value)
            ->where(fn (Builder $q) => $q
                ->whereHas('payments', fn (Builder $p) => $p->where('status', PaymentStatus::Captured->value))
                ->orWhere('orders.failure_reason', ActivateSubscription::AMOUNT_MISMATCH)
                ->orWhere(fn (Builder $open) => $open->where('orders.status', OrderStatus::Created->value)->where('orders.expires_at', '<', now())));
    }
}
