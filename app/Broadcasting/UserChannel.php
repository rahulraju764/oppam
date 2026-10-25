<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Broadcasting\Concerns\ChecksMember;
use App\Enums\UserRole;
use App\Models\User;

/**
 * `App.Models.User.{id}` (PRD §9.3): Laravel notifications, like / interest / view signals,
 * entitlement changes and forced logout for ONE member — only that member, while their account is
 * active. Users have ULID keys, so the id is compared as a string. A bureau login (§11A B.11
 * "notification bell, live toasts", P7.2) may join its own channel too.
 */
final class UserChannel
{
    use ChecksMember;

    public function join(mixed $user, string $id): bool
    {
        $owner = $this->activeMember($user) ?? $this->activeBureauLogin($user);

        return $owner !== null && (string) $owner->id === $id;
    }

    private function activeBureauLogin(mixed $user): ?User
    {
        return $user instanceof User && $user->role === UserRole::Broker && $user->isActive() ? $user : null;
    }
}
