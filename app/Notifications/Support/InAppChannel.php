<?php

declare(strict_types=1);

namespace App\Notifications\Support;

use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Throwable;

/**
 * The in-app copy of a notification (PRD M08, §9.7.1 "persist first, broadcast after"): ONE queued
 * job writes the `notifications` row and only then sends the live signal, so the bell can never
 * hear about a notification that isn't in the database yet (two separate channel jobs on two
 * queues could run in either order). The live signal is sent right here, in this job — not pushed
 * onto a queue again — and is best-effort: the row is the source of truth, so a WebSocket outage
 * is reported and neither fails nor repeats the job (and leaves no failed-job row to replay later).
 */
final class InAppChannel
{
    public function __construct(
        private readonly DatabaseChannel $database,
        private readonly Dispatcher $bus,
    ) {}

    public function send(object $notifiable, MemberNotification $notification): void
    {
        $this->database->send($notifiable, $notification);

        try {
            $this->bus->dispatchNow(new BroadcastEvent(
                new BroadcastNotificationCreated($notifiable, $notification, $notification->toBroadcast($notifiable)->data),
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
