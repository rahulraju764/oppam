<?php

declare(strict_types=1);

namespace App\Domain\Comms;

use App\Models\Announcement;
use App\Models\AnnouncementDismissal;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Which A14 announcements this member sees now (P8.3a): live (inside the window, not ended), for
 * their audience (paid = `profiles.is_premium`, like ad slots), and not dismissed by them. The live
 * list is cached for a minute (every member page shows the banner); dismissals are per member.
 */
final class ActiveAnnouncements
{
    public const CACHE_KEY = 'comms:announcements:live';

    public const MAX_SHOWN = 2;

    /** @return Collection<int, Announcement> */
    public function forMember(User $member): Collection
    {
        $live = $this->live();
        if ($live->isEmpty()) {
            return $live;
        }

        $isPremium = (bool) Profile::query()->where('user_id', $member->id)->value('is_premium');
        $dismissed = AnnouncementDismissal::query()->where('user_id', $member->id)
            ->whereIn('announcement_id', $live->pluck('id'))->pluck('announcement_id')->flip();

        return $live
            ->filter(fn (Announcement $a): bool => $a->isLive() && $a->audience->includes($isPremium) && ! isset($dismissed[$a->id]))
            ->take(self::MAX_SHOWN)
            ->values();
    }

    /** A live announcement this member is in the audience of (dismissed or not), or null. */
    public function liveFor(User $member, string $code): ?Announcement
    {
        $isPremium = (bool) Profile::query()->where('user_id', $member->id)->value('is_premium');

        return $this->live()->first(fn (Announcement $a): bool => $a->code === $code && $a->isLive() && $a->audience->includes($isPremium));
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return Collection<int, Announcement> */
    private function live(): Collection
    {
        /** @var Collection<int, Announcement> */
        return Cache::remember(self::CACHE_KEY, 60, fn (): Collection => Announcement::query()->live()
            ->orderByDesc('starts_at')->orderByDesc('created_at')->limit(10)->get());
    }
}
