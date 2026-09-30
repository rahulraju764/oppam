<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SettingKey;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Setting> */
final class SettingFactory extends Factory
{
    protected $model = Setting::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'key' => SettingKey::SiteSupportEmail->value,
            'value' => fake()->safeEmail(),
        ];
    }

    public function keyed(SettingKey $key, int|bool|string $value): static
    {
        return $this->state(['key' => $key->value, 'value' => $value]);
    }
}
