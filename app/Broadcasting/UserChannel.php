<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Broadcasting\Concerns\ChecksMember;

/**
 * `App.Models.User.{id}` (PRD §9.3): Laravel notifications, like / interest / view signals,
 * entitlement changes and forced logout for ONE member — only that member, while their account is
 * active. Users have ULID keys, so the id is compared as a string.
 */
final class UserChannel
{
    use ChecksMember;

    public function join(mixed $user, string $id): bool
    {
        $member = $this->activeMember($user);

        return $member !== null && (string) $member->id === $id;
    }
}
