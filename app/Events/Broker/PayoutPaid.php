<?php

declare(strict_types=1);

namespace App\Events\Broker;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A payout to the bureau was sent (§11A B.15 `PayoutPaid` on `broker-owner.{broker}`, Owner only):
 * the run code and the net amount as display text — no ids, no bank details, no UTR (the Owner
 * reads those on the statement, through a normal authorized request). The Earnings page re-reads itself.
 */
final class PayoutPaid implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public string $queue = 'broadcasts';

    public function __construct(
        public readonly string $brokerId,
        public readonly string $runCode,
        public readonly string $amount,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('broker-owner.'.$this->brokerId)];
    }

    public function broadcastAs(): string
    {
        return 'payout.paid';
    }

    /** @return array{run: string, amount: string} */
    public function broadcastWith(): array
    {
        return ['run' => $this->runCode, 'amount' => $this->amount];
    }
}
