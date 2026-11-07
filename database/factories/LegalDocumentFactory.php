<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LegalKind;
use App\Models\LegalDocument;
use Database\Factories\Concerns\EditorialStates;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LegalDocument> */
final class LegalDocumentFactory extends Factory
{
    use EditorialStates;

    protected $model = LegalDocument::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [...$this->workflowDefaults('LGL'),
            'kind' => LegalKind::Terms,
            'version' => 1,
            'title' => 'Terms of Use of Oppam Matrimony',
            'summary' => null,
            'body' => '<h3 class="privacy-shd" id="about">1. About</h3><p class="p-main">These are the terms.</p>',
            'effective_on' => now()->toDateString(),
            'is_material' => false,
            'legally_reviewed' => false,
        ];
    }

    public function kind(LegalKind $kind, int $version = 1): static
    {
        return $this->state(['kind' => $kind, 'version' => $version, 'title' => $kind->label()]);
    }

    public function material(): static
    {
        return $this->state(['is_material' => true]);
    }

    /** Published, as the live version. */
    public function live(): static
    {
        return $this->published()->state(['published_at' => now()]);
    }
}
