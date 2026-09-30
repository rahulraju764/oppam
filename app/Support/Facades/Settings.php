<?php

declare(strict_types=1);

namespace App\Support\Facades;

use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Facade;

/**
 * Settings::int(SettingKey::InterestExpiryDays) — typed admin-editable settings (A15).
 *
 * @method static int|bool|string get(\App\Enums\SettingKey $key)
 * @method static int int(\App\Enums\SettingKey $key)
 * @method static bool bool(\App\Enums\SettingKey $key)
 * @method static string string(\App\Enums\SettingKey $key)
 * @method static void flush()
 *
 * @see SettingsRepository
 */
final class Settings extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SettingsRepository::class;
    }
}
