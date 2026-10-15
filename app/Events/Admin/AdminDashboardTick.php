<?php

declare(strict_types=1);

namespace App\Events\Admin;

use App\Enums\Metric;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A dashboard KPI moved between two hourly snapshots (PRD §9.3 `admin.dashboard`: "live KPI ticks
 * — signups, payments today"): the open dashboard shows the new number at once. Payload: the
 * metric key and the number only.
 */
final class AdminDashboardTick implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public string $queue = 'broadcasts';

    public function __construct(
        public readonly Metric $metric,
        public readonly int $value,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin.dashboard')];
    }

    public function broadcastAs(): string
    {
        return 'dashboard.tick';
    }

    /** @return array{metric: string, value: int} */
    public function broadcastWith(): array
    {
        return ['metric' => $this->metric->value, 'value' => $this->value];
    }
}
