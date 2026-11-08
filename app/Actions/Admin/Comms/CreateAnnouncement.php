<?php

declare(strict_types=1);

namespace App\Actions\Admin\Comms;

use App\Models\AdminUser;
use App\Models\Announcement;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Creates and optionally broadcasts a site-wide or targeted banner announcement (A14).
 */
final class CreateAnnouncement
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BroadcastAnnouncement $broadcast,
    ) {}

    /**
     * @param  array{title: string, body: string, action_url?: string|null, action_text?: string|null, starts_at?: string|null, ends_at?: string|null, target_plan?: string|null, is_dismissible?: bool, broadcast_now?: bool}  $data
     *
     * @throws AuthorizationException
     */
    public function handle(AdminUser $admin, array $data): Announcement
    {
        Gate::forUser($admin)->authorize('notifications.broadcast');

        $announcement = new Announcement;
        $announcement->forceFill([
            'title' => trim($data['title']),
            'body' => trim($data['body']),
            'action_url' => ! empty($data['action_url']) ? trim($data['action_url']) : null,
            'action_text' => ! empty($data['action_text']) ? trim($data['action_text']) : null,
            'starts_at' => ! empty($data['starts_at']) ? $data['starts_at'] : null,
            'ends_at' => ! empty($data['ends_at']) ? $data['ends_at'] : null,
            'target_plan' => ! empty($data['target_plan']) ? trim($data['target_plan']) : null,
            'is_dismissible' => $data['is_dismissible'] ?? true,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $announcement->save();

        if (! empty($data['broadcast_now'])) {
            $this->broadcast->handle($admin, $announcement);
        }

        $this->audit->record('comms.announcement_created', $announcement, after: ['title' => $announcement->title], actor: $admin, subjectLabel: $announcement->title);

        return $announcement;
    }
}
