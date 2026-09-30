<?php

declare(strict_types=1);

namespace App\Support\Facades;

use App\Services\Settings\FeatureFlags;
use Illuminate\Support\Facades\Facade;

/**
 * Feature::active(Flag::LikesEnabled) — admin-toggled feature flags (A15).
 *
 * @method static bool active(\App\Enums\Flag $flag)
 * @method static void flush()
 *
 * @see FeatureFlags
 */
final class Feature extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FeatureFlags::class;
    }
}
