<?php

declare(strict_types=1);

namespace App\Events\Comms;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An A14 announcement went live (P8.3a): open member pages refresh their banner. Carries the
 * announcement code only (§9.4: codes, never ids or text) — each banner re-reads what its member
 * may see. On the `broadcasts` queue, after the transaction that published it.
 */
final class AnnouncementPublished implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public string $queue = 'broadcasts';

    public bool $afterCommit = true;

    public function __construct(public readonly string $code) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('announcements');
    }

    public function broadcastAs(): string
    {
        return 'announcement.published';
    }

    /** @return array{code: string} */
    public function broadcastWith(): array
    {
        return ['code' => $this->code];
    }
}
