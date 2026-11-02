<?php

declare(strict_types=1);

namespace App\Queries\Content;

use App\Enums\ProfileStatus;
use App\Models\Branch;
use App\Models\Profile;
use App\Models\SuccessStory;
use Illuminate\Support\Facades\Cache;

/**
 * The real figures behind the counters (M12: members, marriages, branches), cached for a day.
 * Marriages are published stories until M16 (P8.2) records closures.
 */
final class HomeCounters
{
    private const CACHE_KEY = 'content.home_counters';

    /** @return array{members: int, marriages: int, branches: int} */
    public function figures(): array
    {
        /** @var array{members: int, marriages: int, branches: int} */
        return Cache::remember(self::CACHE_KEY, now()->addDay(), fn (): array => [
            'members' => Profile::query()->where('status', ProfileStatus::Active)->count(),
            'marriages' => SuccessStory::query()->live()->count(),
            'branches' => Branch::query()->live()->count(),
        ]);
    }
}
