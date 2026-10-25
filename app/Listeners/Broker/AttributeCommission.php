<?php

declare(strict_types=1);

namespace App\Listeners\Broker;

use App\Actions\Broker\EarnReferralCommission;
use App\Events\Billing\OrderPaid;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * F04 "OrderPaid → broker commission EARNED if referred / managed" (B.10). Queued off the payment
 * path; EarnReferralCommission decides (first paid order, ACTIVE profile and bureau) and is
 * idempotent, so a retried or repeated event earns once.
 */
final class AttributeCommission implements ShouldQueue
{
    public string $queue = 'default';

    public function __construct(private readonly EarnReferralCommission $earn) {}

    public function handle(OrderPaid $event): void
    {
        $profileId = Order::query()->whereKey($event->orderId)->value('profile_id');

        if (is_string($profileId)) {
            $this->earn->handle($profileId);
        }
    }
}
