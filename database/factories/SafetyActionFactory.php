<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CaseOutcome;
use App\Models\Profile;
use App\Models\SafetyAction;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SafetyAction> */
final class SafetyActionFactory extends Factory
{
    protected $model = SafetyAction::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'profile_id' => Profile::factory(),
            'outcome' => CaseOutcome::Warning,
        ];
    }
}
