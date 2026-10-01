<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Models\ModerationItem;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ModerationItem> */
final class ModerationItemFactory extends Factory
{
    protected $model = ModerationItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'type' => ModerationItemType::ProfileNew,
            'profile_id' => Profile::factory(),
            'status' => ModerationStatus::Open,
            'is_priority' => false,
            'submitted_at' => now(),
        ];
    }

    /** A decided rejection with the moderator's note (R-M02-5). */
    public function rejected(string $note = 'Please add your full name as on your ID.'): self
    {
        return $this->state([
            'status' => ModerationStatus::Rejected,
            'decided_at' => now(),
            'reason_category' => 'INCOMPLETE',
            'reason_note' => $note,
        ]);
    }
}
