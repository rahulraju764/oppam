<?php

declare(strict_types=1);

namespace App\Actions\Admin\Comms;

use App\Events\Account\AdminAnnouncementBroadcast;
use App\Models\AdminUser;
use App\Models\Announcement;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Broadcasts an announcement live to online users (A14).
 */
final class BroadcastAnnouncement
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     */
    public function handle(AdminUser $admin, Announcement $announcement): void
    {
        Gate::forUser($admin)->authorize('notifications.broadcast');

        $announcement->forceFill(['broadcast_at' => now()])->save();

        broadcast(new AdminAnnouncementBroadcast($announcement));

        $this->audit->record('comms.announcement_broadcast', $announcement, after: ['broadcast_at' => now()->toIso8601String()], actor: $admin, subjectLabel: $announcement->title);
    }
}
