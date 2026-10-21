<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MemberRestriction;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MemberRestriction> */
final class MemberRestrictionFactory extends Factory
{
    protected $model = MemberRestriction::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'profile_id' => Profile::factory(),
            'until' => now()->addDay(),
            'reason' => 'Like spam',
        ];
    }

    public function on(Profile $profile): self
    {
        return $this->state(['profile_id' => $profile->id]);
    }
}
