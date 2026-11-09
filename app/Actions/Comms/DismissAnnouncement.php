<?php

declare(strict_types=1);

namespace App\Actions\Comms;

use App\Domain\Comms\ActiveAnnouncements;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Models\Announcement;
use App\Models\AnnouncementDismissal;
use App\Models\User;
use App\Services\Admin\Impersonation;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A member closes an announcement banner (A14, P8.3a). Only one this member sees right now and
 * that may be dismissed — anything else is a 404. Idempotent (one row per member and announcement).
 * Not in an admin support session (the member's own choice).
 */
final class DismissAnnouncement
{
    public function __construct(
        private readonly ActiveAnnouncements $active,
        private readonly Impersonation $impersonation,
    ) {}

    /**
     * @throws ImpersonationRestricted
     * @throws NotFoundHttpException
     */
    public function handle(User $member, string $code): void
    {
        $this->impersonation->assertAllowed();

        $announcement = $this->active->liveFor($member, $code);
        if ($announcement === null || ! $announcement->is_dismissible) {
            throw new NotFoundHttpException;
        }

        AnnouncementDismissal::query()->insertOrIgnore([
            'announcement_id' => $announcement->id,
            'user_id' => $member->id,
            'created_at' => now(),
        ]);
    }
}
