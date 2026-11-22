<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SystemAlertKind;
use App\Models\SystemAlert;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An open "failed jobs" alert.
 *
 * @extends Factory<SystemAlert>
 */
final class SystemAlertFactory extends Factory
{
    protected $model = SystemAlert::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'kind' => SystemAlertKind::FailedJobs,
            'open_key' => SystemAlertKind::FailedJobs->value,
            'value' => 14,
            'threshold' => 10,
            'opened_at' => now()->subMinutes(5),
            'last_seen_at' => now(),
            'clear_since' => null,
            'resolved_at' => null,
            'notified_at' => now()->subMinutes(5),
        ];
    }

    public function kind(SystemAlertKind $kind, int $value = 1, int $threshold = 0): self
    {
        return $this->state(['kind' => $kind, 'open_key' => $kind->value, 'value' => $value, 'threshold' => $threshold]);
    }

    public function resolved(?DateTimeInterface $at = null): self
    {
        return $this->state(['open_key' => null, 'resolved_at' => $at ?? now()->subMinute()]);
    }
}
