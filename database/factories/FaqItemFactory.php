<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FaqItem;
use Database\Factories\Concerns\EditorialStates;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FaqItem> */
final class FaqItemFactory extends Factory
{
    use EditorialStates;

    protected $model = FaqItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [...$this->workflowDefaults('FAQ'),
            'question' => 'How do I start?',
            'answer' => '<p>Create your profile.</p>',
        ];
    }
}
