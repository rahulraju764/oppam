<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReferralSource;
use App\Enums\ReferralStatus;
use App\Models\Broker;
use App\Models\BrokerReferral;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BrokerReferral> */
final class BrokerReferralFactory extends Factory
{
    protected $model = BrokerReferral::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'broker_id' => Broker::factory(),
            'profile_id' => Profile::factory(),
            'source' => ReferralSource::Referral,
            'status' => ReferralStatus::Pending,
        ];
    }
}
