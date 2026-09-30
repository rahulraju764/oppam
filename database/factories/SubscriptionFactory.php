<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A current 1-month paid subscription. States: expired(), cancelled(), startingAt($date).
 *
 * @extends Factory<Subscription>
 */
final class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'profile_id' => Profile::factory(),
            'plan_id' => Plan::factory(),
            'status' => SubscriptionStatus::Active,
            'source' => SubscriptionSource::Paid,
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(25),
        ];
    }

    public function expired(): static
    {
        return $this->state(['starts_at' => now()->subMonths(2), 'ends_at' => now()->subMonth()]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => SubscriptionStatus::Cancelled]);
    }

    public function startingAt(string $start, int $months = 12): static
    {
        return $this->state(fn (): array => [
            'starts_at' => $start,
            'ends_at' => now()->parse($start)->addMonthsNoOverflow($months),
        ]);
    }
}
