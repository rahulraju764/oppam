<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Testimonial;
use Database\Factories\Concerns\EditorialStates;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Testimonial> */
final class TestimonialFactory extends Factory
{
    use EditorialStates;

    protected $model = Testimonial::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [...$this->workflowDefaults('TST'),
            'name' => 'Devi',
            'place' => 'Kochi',
            'quote' => 'It felt safe.',
            'image_path' => 'images/home/profile7.webp',
            'image_width' => 600,
            'image_height' => 600,
            'consent_at' => now(),
        ];
    }
}
