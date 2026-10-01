<?php

declare(strict_types=1);

namespace App\Listeners\Admin;

use App\Events\Admin\AdminQueueCountChanged;
use App\Events\Admin\ModerationQueueChanged;
use App\Models\ModerationItem;

/** Push the fresh waiting count of the changed A04 queue to admins on `admin.queues`. */
final class BroadcastModerationQueueCount
{
    public function handle(ModerationQueueChanged $event): void
    {
        AdminQueueCountChanged::dispatch($event->type->queueKey(), ModerationItem::pendingCount($event->type));
    }
}
