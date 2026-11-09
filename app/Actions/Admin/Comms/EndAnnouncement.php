<?php

declare(strict_types=1);

namespace App\Actions\Admin\Comms;

use App\Domain\Comms\ActiveAnnouncements;
use App\Events\Comms\AnnouncementPublished;
use App\Exceptions\Admin\CommsRefused;
use App\Models\AdminUser;
use App\Models\Announcement;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * End an A14 announcement now (P8.3a, `notifications.broadcast`): it leaves every member page live
 * (the same signal makes open banners re-read). Ended is final — start a new one instead. Audited.
 */
final class EndAnnouncement
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ActiveAnnouncements $active,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws CommsRefused
     */
    public function handle(AdminUser $admin, Announcement $announcement): void
    {
        Gate::forUser($admin)->authorize('notifications.broadcast');

        DB::transaction(function () use ($admin, $announcement): void {
            $row = Announcement::query()->whereKey($announcement->id)->lockForUpdate()->firstOrFail();
            if ($row->ended_at !== null) {
                throw CommsRefused::announcementEnded();
            }

            $row->forceFill(['ended_at' => now()])->save();
            DB::afterCommit(fn () => $this->active->forget());   // before the after-commit live signal
            AnnouncementPublished::dispatch($row->code);

            $this->audit->record('comms.announcement_ended', $row, after: ['ended' => true], actor: $admin, subjectLabel: $row->code);
        });

        $this->active->forget();
    }
}
