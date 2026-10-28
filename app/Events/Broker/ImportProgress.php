<?php

declare(strict_types=1);

namespace App\Events\Broker;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A bulk-import batch moved on (§11A B.9 step 2, B.15 `.import.progress`): after every chunk of 100
 * rows and at each status change, live on `broker.{broker}` for the progress bar. Payload: the batch
 * code, its status and row counts only — never cell contents, names or mobiles.
 */
final class ImportProgress implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public string $queue = 'broadcasts';

    public function __construct(
        public readonly string $brokerId,
        public readonly string $batchCode,
        public readonly string $status,
        public readonly int $processed,
        public readonly int $total,
        public readonly int $valid,
        public readonly int $warning,
        public readonly int $error,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('broker.'.$this->brokerId)];
    }

    public function broadcastAs(): string
    {
        return 'import.progress';
    }

    /** @return array{batch: string, status: string, processed: int, total: int, valid: int, warning: int, error: int} */
    public function broadcastWith(): array
    {
        return [
            'batch' => $this->batchCode, 'status' => $this->status, 'processed' => $this->processed, 'total' => $this->total,
            'valid' => $this->valid, 'warning' => $this->warning, 'error' => $this->error,
        ];
    }
}
