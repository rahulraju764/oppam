<?php

declare(strict_types=1);

namespace App\Listeners\Billing;

use App\Events\Admin\AdminRevenueChanged;
use App\Events\Billing\OrderPaid;
use App\Events\Billing\RefundProcessed;

/** A paid order or a processed refund tells open A02 dashboards; a lost signal never touches the money. */
final class AnnounceRevenueChange
{
    public function handle(OrderPaid|RefundProcessed $event): void
    {
        rescue(fn () => event(new AdminRevenueChanged), report: true);
    }
}
