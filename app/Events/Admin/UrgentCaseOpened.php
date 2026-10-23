<?php

declare(strict_types=1);

namespace App\Events\Admin;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An A10 abuse case became P0 (PRD A10 "Live: new P0 report pings on-call admins via
 * admin.queues"): every open admin page shows an urgent toast linking to the case. Sent at once.
 * Payload: the case code only — never the member, the reporters or the reason text.
 */
final class UrgentCaseOpened implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly string $caseCode) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin.queues')];
    }

    public function broadcastAs(): string
    {
        return 'case.urgent';
    }

    /** @return array{case: string} */
    public function broadcastWith(): array
    {
        return ['case' => $this->caseCode];
    }
}
