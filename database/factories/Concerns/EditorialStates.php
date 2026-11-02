<?php

declare(strict_types=1);

namespace Database\Factories\Concerns;

use App\Enums\ContentStatus;
use DateTimeInterface;
use Illuminate\Support\Str;

/** Workflow states shared by the A08 factories. A new row is a DRAFT. */
trait EditorialStates
{
    public function published(): static
    {
        return $this->state(fn (): array => ['status' => ContentStatus::Published, 'published_at' => now()]);
    }

    public function inReview(): static
    {
        return $this->state(fn (): array => ['status' => ContentStatus::InReview]);
    }

    public function scheduled(?DateTimeInterface $at = null): static
    {
        return $this->state(fn (): array => ['status' => ContentStatus::Scheduled, 'scheduled_at' => $at ?? now()->addHour()]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['status' => ContentStatus::Archived, 'archived_at' => now()]);
    }

    /** @return array{code: string, status: ContentStatus, sort_order: int} */
    protected function workflowDefaults(string $prefix): array
    {
        return ['code' => $prefix.Str::upper(Str::random(8)), 'status' => ContentStatus::Draft, 'sort_order' => 0];
    }
}
