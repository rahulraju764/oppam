<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Events\Notifications\NotificationsRead;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Models\User;
use App\Services\Admin\Impersonation;
use App\Support\Realtime\LiveSignal;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Mark read / delete a member's in-app notifications (M08). Only the member's own rows are ever
 * touched (an id that isn't theirs simply matches nothing); afterwards every open tab gets the new
 * unread count (best-effort live signal). Never during an admin support session: reading or
 * deleting the member's notifications for them would hide something they haven't seen.
 */
final class UpdateNotifications
{
    public function __construct(private readonly Impersonation $impersonation) {}

    /** @throws ImpersonationRestricted an admin looking around as the member changes nothing (P1.7b) */
    public function markRead(User $member, ?string $notificationId = null): int
    {
        $this->impersonation->assertAllowed();

        $updated = $this->own($member)
            ->whereNull('read_at')
            ->when($notificationId !== null, fn ($q) => $q->whereKey($notificationId))
            ->update(['read_at' => now()]);

        if ($updated > 0) {
            $this->announce($member);
        }

        return $updated;
    }

    /** @throws ImpersonationRestricted */
    public function delete(User $member, string $notificationId): bool
    {
        $this->impersonation->assertAllowed();

        $deleted = $this->own($member)->whereKey($notificationId)->delete() > 0;

        if ($deleted) {
            $this->announce($member);
        }

        return $deleted;
    }

    public function unreadCount(User $member): int
    {
        return $this->own($member)->whereNull('read_at')->count();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<DatabaseNotification> */
    private function own(User $member): \Illuminate\Database\Eloquent\Builder
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', $member->getMorphClass())
            ->where('notifiable_id', $member->id);
    }

    private function announce(User $member): void
    {
        LiveSignal::afterCommit(new NotificationsRead((string) $member->id, $this->unreadCount($member)));
    }
}
