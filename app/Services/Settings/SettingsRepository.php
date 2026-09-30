<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Enums\SettingKey;
use App\Models\Setting;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Typed, cached read access to admin-editable settings (PRD A15). One cache entry holds every
 * stored value; a key without a row falls back to its SettingKey default. Writes happen only in
 * App\Actions\Admin\Settings\UpdateSetting, which calls flush().
 */
final class SettingsRepository
{
    // Backstop only: writes flush the cache after commit. Bounds a stale value left by a reader
    // racing a flush (it would otherwise live until the next edit).
    private const CACHE_TTL_SECONDS = 3600;

    private const CACHE_KEY = 'settings:all';

    /** @var array<string, mixed>|null in-request copy */
    private ?array $values = null;

    public function __construct(private readonly Cache $cache) {}

    public function get(SettingKey $key): int|bool|string
    {
        $stored = $this->stored();

        return array_key_exists($key->value, $stored)
            ? $key->type()->cast($stored[$key->value])
            : $key->default();
    }

    public function int(SettingKey $key): int
    {
        return (int) $this->get($key);
    }

    public function bool(SettingKey $key): bool
    {
        return (bool) $this->get($key);
    }

    public function string(SettingKey $key): string
    {
        return (string) $this->get($key);
    }

    public function flush(): void
    {
        $this->values = null;
        $this->cache->forget(self::CACHE_KEY);
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        return $this->values ??= $this->cache->remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn (): array => Setting::query()->pluck('value', 'key')->all(),
        );
    }
}
