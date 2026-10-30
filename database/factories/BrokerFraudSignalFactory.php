<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BrokerSignal;
use App\Models\Broker;
use App\Models\BrokerFraudSignal;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BrokerFraudSignal> */
final class BrokerFraudSignalFactory extends Factory
{
    protected $model = BrokerFraudSignal::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'broker_id' => Broker::factory(),
            'type' => BrokerSignal::ReferralSpike,
            'details' => ['this_month' => 30, 'average' => 4],
            'open_key' => fn (array $a): string => BrokerFraudSignal::openKey((string) $a['broker_id'], $a['type'] instanceof BrokerSignal ? $a['type'] : BrokerSignal::from((string) $a['type'])),
            'detected_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
