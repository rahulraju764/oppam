<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ConversationParticipant> */
final class ConversationParticipantFactory extends Factory
{
    protected $model = ConversationParticipant::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'profile_id' => Profile::factory(),
        ];
    }
}
