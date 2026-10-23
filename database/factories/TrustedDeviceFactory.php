<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TrustedDevice> */
final class TrustedDeviceFactory extends Factory
{
    protected $model = TrustedDevice::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'device_hash' => hash('sha256', fake()->uuid()),
            'verified_at' => now(),
        ];
    }
}
