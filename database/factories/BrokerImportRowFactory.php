<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ImportRowStatus;
use App\Models\BrokerImportBatch;
use App\Models\BrokerImportRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BrokerImportRow> */
final class BrokerImportRowFactory extends Factory
{
    protected $model = BrokerImportRow::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'batch_id' => BrokerImportBatch::factory(),
            'row_number' => fake()->unique()->numberBetween(1, 5000),
            'data' => [],
            'status' => ImportRowStatus::Valid,
        ];
    }
}
