<?php

declare(strict_types=1);

namespace App\Domain\Safety;

use App\Models\Block;
use App\Models\Profile;

/**
 * Blocks are symmetric and silent (R-M03-1, PRD §7.2): if either profile blocked the other,
 * neither sees the other's profile (404), events or notifications. THE place that answers
 * "is this pair blocked?" — search (P2.1) uses excludeBlockedFor() scopes built on the same rule.
 */
final class BlockList
{
    public function isBlocked(Profile $a, Profile $b): bool
    {
        return Block::query()
            ->where(fn ($q) => $q->where('blocker_profile_id', $a->id)->where('blocked_profile_id', $b->id))
            ->orWhere(fn ($q) => $q->where('blocker_profile_id', $b->id)->where('blocked_profile_id', $a->id))
            ->exists();
    }

    /**
     * Every profile id this profile must never see (blocked by them, or blocking them).
     *
     * @return list<string>
     */
    public function hiddenFrom(Profile $profile): array
    {
        $blocked = Block::query()->where('blocker_profile_id', $profile->id)->pluck('blocked_profile_id');
        $blockers = Block::query()->where('blocked_profile_id', $profile->id)->pluck('blocker_profile_id');

        return $blocked->merge($blockers)->map(fn ($id): string => (string) $id)->unique()->values()->all();
    }
}
