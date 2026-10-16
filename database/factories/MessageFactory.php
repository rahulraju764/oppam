<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Message> */
final class MessageFactory extends Factory
{
    protected $model = Message::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'client_id' => (string) Str::uuid(),
            'type' => MessageType::Text,
            'body' => $this->faker->sentence(),
        ];
    }

    /** A TEXT message from this profile in this conversation. */
    public function from(Profile $sender, Conversation $conversation): self
    {
        return $this->state(['conversation_id' => $conversation->id, 'sender_profile_id' => $sender->id]);
    }

    public function system(): self
    {
        return $this->state(['type' => MessageType::System, 'sender_profile_id' => null, 'body' => 'You are now connected']);
    }
}
