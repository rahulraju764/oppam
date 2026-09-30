<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OtpPurpose;
use App\Models\OtpChallenge;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * A live code for "123456" unless code() is used. Tests only — real codes are random.
 *
 * @extends Factory<OtpChallenge>
 */
final class OtpChallengeFactory extends Factory
{
    protected $model = OtpChallenge::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'phone' => '+9190000'.$this->faker->unique()->numerify('#####'),
            'purpose' => OtpPurpose::Register,
            'code_hash' => Hash::make('123456'),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(5),
        ];
    }

    public function code(string $code): static
    {
        return $this->state(['code_hash' => Hash::make($code)]);
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }
}
