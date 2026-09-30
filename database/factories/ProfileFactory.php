<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\PhysicalStatus;
use App\Enums\ProfileStatus;
use App\Models\Masters\Caste;
use App\Models\Masters\MotherTongue;
use App\Models\Profile;
use App\Models\User;
use App\Services\Profile\ProfileCodeGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fake profiles. `code` and `status` are not mass-assignable on the model, but factories
 * bypass that on purpose (they build fixtures, not requests); the code still comes from the
 * real ProfileCodeGenerator so tests exercise it.
 *
 * States: active(), pendingReview(), draft(), rejected(), suspended(), male(), female(),
 * verified(), premium(), incomplete(), withAge(n).
 *
 * @extends Factory<Profile>
 */
final class ProfileFactory extends Factory
{
    protected $model = Profile::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $gender = $this->faker->randomElement(Gender::cases());

        return [
            'user_id' => User::factory(),
            'code' => fn (): string => app(ProfileCodeGenerator::class)->next(),
            'gender' => $gender,
            'first_name' => $this->faker->firstName($gender === Gender::Female ? 'female' : 'male'),
            'last_name' => $this->faker->lastName(),
            'dob' => $this->faker->dateTimeBetween('-38 years', '-22 years')->format('Y-m-d'),
            'height_cm' => $gender === Gender::Female ? $this->faker->numberBetween(148, 172) : $this->faker->numberBetween(160, 188),
            'weight_kg' => $this->faker->numberBetween(45, 90),
            'marital_status' => MaritalStatus::NeverMarried,
            'children_count' => 0,
            'physical_status' => PhysicalStatus::Normal,
            'caste_id' => Caste::factory(),
            'religion_id' => fn (array $attributes): int => Caste::query()->findOrFail($attributes['caste_id'])->religion_id,
            'mother_tongue_id' => MotherTongue::factory(),
            'about' => $this->faker->paragraph(),
            'status' => ProfileStatus::Active,
            'completeness' => $this->faker->numberBetween(60, 100),
            'published_at' => now(),
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => ProfileStatus::Active, 'published_at' => now()]);
    }

    public function pendingReview(): static
    {
        return $this->state(['status' => ProfileStatus::PendingReview, 'published_at' => null]);
    }

    public function draft(): static
    {
        return $this->state(['status' => ProfileStatus::Draft, 'published_at' => null, 'completeness' => 25]);
    }

    public function rejected(): static
    {
        return $this->state(['status' => ProfileStatus::Rejected, 'published_at' => null]);
    }

    public function suspended(): static
    {
        return $this->state(['status' => ProfileStatus::Suspended]);
    }

    public function male(): static
    {
        return $this->state(fn (): array => [
            'gender' => Gender::Male,
            'first_name' => $this->faker->firstName('male'),
            'height_cm' => $this->faker->numberBetween(160, 188),
        ]);
    }

    public function female(): static
    {
        return $this->state(fn (): array => [
            'gender' => Gender::Female,
            'first_name' => $this->faker->firstName('female'),
            'height_cm' => $this->faker->numberBetween(148, 172),
        ]);
    }

    public function verified(): static
    {
        return $this->state(['is_verified' => true]);
    }

    public function premium(): static
    {
        return $this->state(['is_premium' => true]);
    }

    public function incomplete(): static
    {
        return $this->state(['completeness' => 40]);
    }

    public function withAge(int $years): static
    {
        return $this->state(['dob' => now(config('oppam.display_timezone'))->subYears($years)->subDays(10)->format('Y-m-d')]);
    }
}
