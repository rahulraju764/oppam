<?php

declare(strict_types=1);

namespace App\Jobs\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Queue\InteractsWithQueue;

/** R-M08-4: in-app notifications older than KEEP_DAYS are deleted nightly, in batches (created_at index). */
final class PruneOldNotifications implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const KEEP_DAYS = 180;

    private const BATCH = 5000;

    public int $timeout = 600;

    public function handle(): void
    {
        $cutoff = now()->subDays(self::KEEP_DAYS);

        do {
            $deleted = DatabaseNotification::query()->where('created_at', '<', $cutoff)->limit(self::BATCH)->delete();
        } while ($deleted === self::BATCH);
    }
}
