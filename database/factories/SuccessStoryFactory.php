<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SuccessStory;
use Database\Factories\Concerns\EditorialStates;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SuccessStory> */
final class SuccessStoryFactory extends Factory
{
    use EditorialStates;

    protected $model = SuccessStory::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [...$this->workflowDefaults('STY'),
            'slug' => Str::lower(Str::random(12)),
            'couple' => 'Asha & Binu',
            'place' => 'Thrissur',
            'wedding_date' => '2024-01-15',
            'quote' => 'We matched in the first week.',
            'body' => '<p>Their story.</p>',
            'image_path' => 'images/profile/story-couple.webp',
            'image_width' => 500,
            'image_height' => 500,
            'consent_a_at' => now(),
            'consent_b_at' => now(),
            'consent_note' => 'Both signed the consent form.',
        ];
    }
}
