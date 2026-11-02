<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\HomeBlockKey;
use App\Models\HomeBlock;
use Database\Factories\Concerns\EditorialStates;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HomeBlock> */
final class HomeBlockFactory extends Factory
{
    use EditorialStates;

    protected $model = HomeBlock::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [...$this->workflowDefaults('BLK'),
            'block_key' => HomeBlockKey::Plans,
            'content' => ['eyebrow' => 'Plans', 'heading' => 'Choose a plan', 'text' => 'Upgrade any time.'],
        ];
    }
}
