<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ConsentDisputeStatus;
use App\Enums\DisputeSource;
use App\Enums\ProfileStatus;
use App\Models\AdminUser;
use App\Models\Broker;
use App\Models\BrokerConsentDispute;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BrokerConsentDispute> */
final class BrokerConsentDisputeFactory extends Factory
{
    protected $model = BrokerConsentDispute::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'broker_id' => Broker::factory(),
            'profile_id' => fn (array $attributes) => Profile::factory()->brokerManaged(Broker::query()->findOrFail($attributes['broker_id']))->suspended(),
            'source' => DisputeSource::Call,
            'note' => 'Client called: never agreed to a profile.',
            'status' => ConsentDisputeStatus::Open,
            'profile_status_before' => ProfileStatus::Active,
            'recorded_by_admin_id' => AdminUser::factory(),
        ];
    }

    public function upheld(): static
    {
        return $this->state(fn (): array => ['status' => ConsentDisputeStatus::Upheld, 'decided_at' => now()]);
    }
}
