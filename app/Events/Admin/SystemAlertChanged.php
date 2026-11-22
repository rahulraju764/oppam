<?php

declare(strict_types=1);

namespace App\Events\Admin;

use App\Enums\SystemAlertKind;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An A12 alert opened or resolved (P9.2b2): open A02 dashboards and health panels re-read
 * themselves. Carries the kind and the state only — no ids, no figures; the numbers come from the
 * authorized render.
 */
final class SystemAlertChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public const OPENED = 'opened';

    public const RESOLVED = 'resolved';

    public string $queue = 'broadcasts';

    public function __construct(
        public readonly SystemAlertKind $kind,
        public readonly string $state,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin.dashboard')];
    }

    public function broadcastAs(): string
    {
        return 'system.alert';
    }

    /** @return array{kind: string, state: string} */
    public function broadcastWith(): array
    {
        return ['kind' => $this->kind->value, 'state' => $this->state];
    }
}
