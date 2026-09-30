<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Dosham;
use App\Models\HoroscopeDetail;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HoroscopeDetail> */
final class HoroscopeDetailFactory extends Factory
{
    protected $model = HoroscopeDetail::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'profile_id' => Profile::factory(),
            'birth_place' => $this->faker->city(),
            'chovva_dosham' => Dosham::No,
            'papa_dosham' => Dosham::DontKnow,
        ];
    }
}
