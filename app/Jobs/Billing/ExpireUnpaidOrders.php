<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Hourly: unpaid orders past `expires_at` become EXPIRED (M10). A payment captured later for one
 * still activates it (ActivateSubscription) — the member paid; expiry only tidies the open list.
 * One indexed UPDATE (orders.status + expires_at); safe to re-run.
 */
final class ExpireUnpaidOrders implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        Order::query()
            ->where('status', OrderStatus::Created->value)
            ->where('expires_at', '<', now())
            ->update(['status' => OrderStatus::Expired->value, 'updated_at' => now()]);
    }
}
