<?php

declare(strict_types=1);

namespace App\Listeners\Admin;

use App\Enums\ModerationItemType;
use App\Events\Admin\AdminQueueCountChanged;
use App\Events\Profile\ProfileSubmitted;
use App\Models\ModerationItem;

/** A new profile is waiting: push the fresh profile-queue count to admins (M02 acceptance, A04). */
final class BroadcastProfileQueueCount
{
    public function handle(ProfileSubmitted $event): void
    {
        $type = ModerationItemType::ProfileNew;

        AdminQueueCountChanged::dispatch($type->queueKey(), ModerationItem::pendingCount($type));
    }
}
