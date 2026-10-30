<?php

declare(strict_types=1);

namespace App\Events\Broker;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A07 suspended or deactivated the bureau (§11A B.15 `BrokerSuspended`, R-A07-8): every open portal
 * tab of the bureau — Owner (`broker-owner.{broker}`) and staff (`broker.{broker}`). The tabs are
 * sent to sign-in by each login's ForceLogout (Dusk P7.8), and broker.active refuses their next
 * request; no page listens to this event today — it is the B.15 bureau-level event for later
 * listeners (e.g. a banner), sent through LiveSignal::afterCommit. Payload: the new status only —
 * never the admin's reason.
 */
final class BrokerSuspended implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public readonly string $brokerId,
        public readonly string $status,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('broker-owner.'.$this->brokerId), new PrivateChannel('broker.'.$this->brokerId)];
    }

    public function broadcastAs(): string
    {
        return 'bureau.suspended';
    }

    /** @return array{status: string} */
    public function broadcastWith(): array
    {
        return ['status' => $this->status];
    }
}
