<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Broadcasting\Concerns\ChecksMember;
use App\Services\Admin\Impersonation;

/**
 * private-announcements (A14, P8.3a): every signed-in, active member account may listen — the
 * payload is only an announcement code, and the banner then reads what this member may see through
 * its own render (ActiveAnnouncements). Not in an admin support session. Re-checked on every
 * subscribe against the database (§9.3).
 */
final class AnnouncementsChannel
{
    use ChecksMember;

    public function __construct(private readonly Impersonation $impersonation) {}

    public function join(mixed $user): bool
    {
        return $this->activeMember($user) !== null && ! $this->impersonation->isActive();
    }
}
