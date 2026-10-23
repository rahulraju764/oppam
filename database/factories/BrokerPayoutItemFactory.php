<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PayoutItemStatus;
use App\Models\Broker;
use App\Models\BrokerPayoutItem;
use App\Models\BrokerPayoutRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BrokerPayoutItem> */
final class BrokerPayoutItemFactory extends Factory
{
    protected $model = BrokerPayoutItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'run_id' => BrokerPayoutRun::factory(),
            'broker_id' => Broker::factory(),
            'status' => PayoutItemStatus::Pending,
        ];
    }
}
