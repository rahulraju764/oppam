<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BannedIdentifierType;
use App\Models\BannedIdentifier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BannedIdentifier> */
final class BannedIdentifierFactory extends Factory
{
    protected $model = BannedIdentifier::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'type' => BannedIdentifierType::Phone,
            'value' => hash('sha256', fake()->unique()->uuid()),
        ];
    }
}
