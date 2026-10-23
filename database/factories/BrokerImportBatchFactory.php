<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ImportBatchStatus;
use App\Models\Broker;
use App\Models\BrokerImportBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BrokerImportBatch> */
final class BrokerImportBatchFactory extends Factory
{
    protected $model = BrokerImportBatch::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => 'B-'.fake()->unique()->numerify('####'),
            'broker_id' => Broker::factory(),
            'uploaded_by_user_id' => User::factory(),
            'file_sha256' => hash('sha256', fake()->uuid()),
            'status' => ImportBatchStatus::Uploaded,
        ];
    }
}
