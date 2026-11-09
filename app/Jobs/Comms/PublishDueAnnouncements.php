<?php

declare(strict_types=1);

namespace App\Jobs\Comms;

use App\Domain\Comms\ActiveAnnouncements;
use App\Events\Comms\AnnouncementPublished;
use App\Models\Announcement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Every minute (routes/console.php): a scheduled A14 announcement whose start has come goes out
 * live, once (broadcast_at is claimed by a conditional update, so overlapping runs send it once);
 * one whose end has passed makes open banners re-read so it leaves (once: end_signalled_at).
 */
final class PublishDueAnnouncements implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(ActiveAnnouncements $active): void
    {
        $changed = false;

        foreach (Announcement::query()->live()->whereNull('broadcast_at')->limit(50)->get() as $announcement) {
            if (Announcement::query()->whereKey($announcement->id)->whereNull('broadcast_at')->update(['broadcast_at' => now()]) === 1) {
                AnnouncementPublished::dispatch($announcement->code);
                $changed = true;
            }
        }

        // Ended by its window (however late this run is): open banners are told once.
        foreach (Announcement::query()->whereNull('ended_at')->whereNull('end_signalled_at')->where('ends_at', '<=', now())->limit(50)->get() as $announcement) {
            if (Announcement::query()->whereKey($announcement->id)->whereNull('end_signalled_at')->update(['end_signalled_at' => now()]) === 1) {
                AnnouncementPublished::dispatch($announcement->code);
                $changed = true;
            }
        }

        if ($changed) {
            $active->forget();
        }
    }
}
