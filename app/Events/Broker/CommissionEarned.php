<?php

declare(strict_types=1);

namespace App\Events\Broker;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A referral / managed conversion earned commission (B.10: live "commission earned" on
 * `broker-owner.{broker}`, Owner only). Payload: the profile code and the amount as display text —
 * no ids, no member details (R-M13-7). The open Referrals page re-reads itself.
 */
final class CommissionEarned implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public string $queue = 'broadcasts';

    public function __construct(
        public readonly string $brokerId,
        public readonly string $profileCode,
        public readonly string $amount,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('broker-owner.'.$this->brokerId)];
    }

    public function broadcastAs(): string
    {
        return 'commission.earned';
    }

    /** @return array{profile: string, amount: string} */
    public function broadcastWith(): array
    {
        return ['profile' => $this->profileCode, 'amount' => $this->amount];
    }
}
