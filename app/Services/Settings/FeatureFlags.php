<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Enums\Flag;
use App\Models\FeatureFlag;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Cached feature-flag lookups (PRD A15): Feature::active(Flag::LikesEnabled). A flag without a
 * row is OFF. Toggled only by the ToggleFeatureFlag Action, which calls flush().
 */
final class FeatureFlags
{
    // Backstop only: writes flush the cache after commit. Bounds a stale value left by a reader
    // racing a flush (it would otherwise live until the next edit).
    private const CACHE_TTL_SECONDS = 3600;

    private const CACHE_KEY = 'feature-flags:all';

    /** @var array<string, bool>|null */
    private ?array $states = null;

    public function __construct(private readonly Cache $cache) {}

    public function active(Flag $flag): bool
    {
        return $this->states()[$flag->value] ?? false;
    }

    public function flush(): void
    {
        $this->states = null;
        $this->cache->forget(self::CACHE_KEY);
    }

    /** @return array<string, bool> */
    private function states(): array
    {
        return $this->states ??= $this->cache->remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn (): array => FeatureFlag::query()->pluck('is_enabled', 'key')->map(fn (mixed $on): bool => (bool) $on)->all(),
        );
    }
}
