<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Broadcasting\Concerns\ChecksMember;
use App\Models\Profile;

/**
 * `inbox.{profile}` (PRD §9.3): conversation list order and unread counts across threads — only
 * for the member who owns that profile, while their account is active.
 */
final class InboxChannel
{
    use ChecksMember;

    public function join(mixed $user, string $profile): bool
    {
        $member = $this->activeMember($user);

        return $member !== null && Profile::query()->whereKey($profile)->where('user_id', $member->id)->exists();
    }
}
