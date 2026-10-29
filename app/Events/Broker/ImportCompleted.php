<?php

declare(strict_types=1);

namespace App\Events\Broker;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An import run ended (§11A B.9 step 5, B.15 `ImportCompleted` → toast): live on `broker.{broker}`.
 * `status` is COMPLETED, or READY when it stopped early (`stopped` = DAILY_CAP / BUREAU_BLOCKED).
 * Payload: the batch code, status and counts only — never names, mobiles or profile codes.
 */
final class ImportCompleted implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public string $queue = 'broadcasts';

    public function __construct(
        public readonly string $brokerId,
        public readonly string $batchCode,
        public readonly string $status,
        public readonly int $imported,
        public readonly int $skipped,
        public readonly int $waiting,
        public readonly ?string $stopped,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('broker.'.$this->brokerId)];
    }

    public function broadcastAs(): string
    {
        return 'import.completed';
    }

    /** @return array{batch: string, status: string, imported: int, skipped: int, waiting: int, stopped: string|null} */
    public function broadcastWith(): array
    {
        return [
            'batch' => $this->batchCode, 'status' => $this->status, 'imported' => $this->imported,
            'skipped' => $this->skipped, 'waiting' => $this->waiting, 'stopped' => $this->stopped,
        ];
    }
}
