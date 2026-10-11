<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InterestStatus;
use App\Models\Interest;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Interest> */
final class InterestFactory extends Factory
{
    protected $model = Interest::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'from_profile_id' => Profile::factory(),
            'to_profile_id' => Profile::factory(),
            'status' => InterestStatus::Pending,
            'sent_at' => now(),
            'expires_at' => now()->addDays(30),
        ];
    }

    public function between(Profile $from, Profile $to): self
    {
        return $this->state(['from_profile_id' => $from->id, 'to_profile_id' => $to->id]);
    }

    public function status(InterestStatus $status): self
    {
        return $this->state(['status' => $status, 'responded_at' => $status === InterestStatus::Pending ? null : now()]);
    }

    public function accepted(): self
    {
        return $this->status(InterestStatus::Accepted);
    }

    public function declined(): self
    {
        return $this->status(InterestStatus::Declined);
    }
}
