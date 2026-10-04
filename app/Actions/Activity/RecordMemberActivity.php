<?php

declare(strict_types=1);

namespace App\Actions\Activity;

use App\Enums\UserRole;
use App\Models\Profile;
use App\Models\User;
use App\Services\Admin\Impersonation;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * "Last seen" (PRD §9.6) and the profile's "last active" (search sort, active-within filter,
 * match scoring): written for a member at most once per DEBOUNCE_SECONDS, on requests and on
 * joining the presence channel. Reverb sends no presence webhooks, so "on leaving" (PRD) becomes
 * "on activity" — accurate to a few minutes. An admin impersonating the member never counts as
 * the member being active. Showing last seen to others honours privacy_settings.show_last_seen.
 */
final class RecordMemberActivity
{
    public const DEBOUNCE_SECONDS = 300;

    public function __construct(
        private readonly Cache $cache,
        private readonly Impersonation $impersonation,
    ) {}

    public function handle(User $user): void
    {
        if ($user->role !== UserRole::Member || $this->impersonation->isActive()) {
            return;
        }

        // Cache::add is atomic: only the first request in the window writes.
        if (! $this->cache->add('member-activity:'.$user->id, 1, self::DEBOUNCE_SECONDS)) {
            return;
        }

        $now = now();
        User::query()->whereKey($user->id)->update(['last_seen_at' => $now]);
        Profile::query()->where('user_id', $user->id)->update(['last_active_at' => $now]);
    }
}
