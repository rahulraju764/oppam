<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AdAudience;
use App\Enums\AdSlot;
use App\Models\AdCreative;
use Database\Factories\Concerns\EditorialStates;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AdCreative> */
final class AdCreativeFactory extends Factory
{
    use EditorialStates;

    protected $model = AdCreative::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [...$this->workflowDefaults('ADV'),
            'slot' => AdSlot::Promo,
            'audience' => AdAudience::All,
            'weight' => 1,
            'starts_at' => null,
            'ends_at' => null,
            'heading' => 'Onam offer',
            'copy' => '20% off Gold this week.',
            'cta_label' => 'See plans',
            'cta_url' => '/plans',
            'alt' => null,
        ];
    }

    public function slot(AdSlot $slot): static
    {
        return $this->state(fn (): array => ['slot' => $slot] + ($slot->needsImage()
            ? ['image_path' => 'images/home/ad1.jpg', 'image_width' => 800, 'image_height' => 800]
            : []));
    }

    public function audience(AdAudience $audience): static
    {
        return $this->state(['audience' => $audience]);
    }
}
