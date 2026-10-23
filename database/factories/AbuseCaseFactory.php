<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AbuseCaseStatus;
use App\Enums\CaseOutcome;
use App\Enums\CasePriority;
use App\Models\AbuseCase;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AbuseCase> */
final class AbuseCaseFactory extends Factory
{
    protected $model = AbuseCase::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => 'CASE'.fake()->unique()->numberBetween(90000, 99999),
            'reported_profile_id' => Profile::factory(),
            'open_key' => fn (array $attributes): string => (string) $attributes['reported_profile_id'],
            'status' => AbuseCaseStatus::Open,
            'priority' => CasePriority::P1,
            'sla_due_at' => now()->addDay(),
            'reports_count' => 1,
            'reporters_count' => 1,
            'last_reported_at' => now(),
        ];
    }

    public function priority(CasePriority $priority): self
    {
        return $this->state(['priority' => $priority]);
    }

    public function closed(CaseOutcome $outcome = CaseOutcome::NoAction): self
    {
        return $this->state(['status' => AbuseCaseStatus::Closed, 'open_key' => null, 'outcome' => $outcome, 'decided_at' => now()]);
    }
}
