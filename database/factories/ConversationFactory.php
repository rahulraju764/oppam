<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Conversation> */
final class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'pair_key' => Str::lower((string) Str::ulid()).'|'.Str::lower((string) Str::ulid()),
            'status' => ConversationStatus::Open,
        ];
    }

    /** A thread between two profiles, with both participant rows. */
    public function between(Profile $a, Profile $b): self
    {
        return $this->state(['pair_key' => Conversation::pairKey($a->id, $b->id)])
            ->afterCreating(function (Conversation $conversation) use ($a, $b): void {
                foreach ([$a, $b] as $profile) {
                    $participant = new ConversationParticipant;
                    $participant->forceFill(['conversation_id' => $conversation->id, 'profile_id' => $profile->id])->save();
                }
            });
    }

    public function status(ConversationStatus $status): self
    {
        return $this->state(['status' => $status]);
    }
}
