<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PayoutRunStatus;
use App\Models\BrokerPayoutRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BrokerPayoutRun> */
final class BrokerPayoutRunFactory extends Factory
{
    protected $model = BrokerPayoutRun::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'period_start' => now()->startOfMonth()->subMonth()->toDateString(),
            'period_end' => now()->startOfMonth()->subDay()->toDateString(),
            'status' => PayoutRunStatus::Draft,
        ];
    }
}
