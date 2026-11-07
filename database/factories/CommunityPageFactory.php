<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CommunityPage;
use Database\Factories\Concerns\EditorialStates;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunityPage> */
final class CommunityPageFactory extends Factory
{
    use EditorialStates;

    protected $model = CommunityPage::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [...$this->workflowDefaults('COM'),
            'slug' => 'nair-matrimony-'.fake()->unique()->lexify('????'),
            'heading' => 'Nair Matrimony',
            'intro' => '<p>Find Nair brides and grooms in Kerala.</p>',
            'meta_description' => 'Nair matrimony in Kerala: verified profiles.',
            'religion_id' => null,
            'caste_id' => null,
        ];
    }
}
