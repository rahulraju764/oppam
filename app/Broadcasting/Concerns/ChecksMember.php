<?php

declare(strict_types=1);

namespace App\Broadcasting\Concerns;

use App\Enums\UserRole;
use App\Models\Profile;
use App\Models\User;

/**
 * The member side of every member channel: a signed-in, ACTIVE member account (the guard loads the
 * user from the database on each auth request, so a suspension made a second ago already counts).
 */
trait ChecksMember
{
    private function activeMember(mixed $user): ?User
    {
        return $user instanceof User && $user->role === UserRole::Member && $user->isActive() ? $user : null;
    }

    private function profileOf(User $user): ?Profile
    {
        return Profile::query()->where('user_id', $user->id)->first();
    }
}
