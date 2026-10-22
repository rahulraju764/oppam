<?php

declare(strict_types=1);

namespace App\Events\Verification;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The member's ID verification changed (F05 "approve → Verified badge (live)") — `.verification.changed`
 * on their private channel, after the decision commits. A hint only: /verify and /me re-read
 * themselves. The new status and whether the badge is on — no ids, no document data.
 */
final class VerificationChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public string $queue = 'broadcasts';

    public function __construct(
        public readonly string $userId,
        public readonly string $status,
        public readonly bool $verified,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'verification.changed';
    }

    /** @return array{status: string, verified: bool} */
    public function broadcastWith(): array
    {
        return ['status' => $this->status, 'verified' => $this->verified];
    }
}
