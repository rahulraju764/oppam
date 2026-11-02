<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Banner;
use Database\Factories\Concerns\EditorialStates;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Banner> */
final class BannerFactory extends Factory
{
    use EditorialStates;

    protected $model = Banner::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [...$this->workflowDefaults('BNR'),
            'heading' => 'Join Today',
            'copy' => 'Begin your journey.',
            'image_path' => 'images/banner/banner1.webp',
            'image_width' => 1920,
            'image_height' => 700,
        ];
    }
}
