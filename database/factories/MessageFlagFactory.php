<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MessageFlagRule;
use App\Enums\MessageFlagSource;
use App\Enums\MessageFlagStatus;
use App\Models\Message;
use App\Models\MessageFlag;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MessageFlag> */
final class MessageFlagFactory extends Factory
{
    protected $model = MessageFlag::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
            'conversation_id' => fn (array $attributes): string => (string) Message::query()->whereKey($attributes['message_id'])->value('conversation_id'),
            'sender_profile_id' => fn (array $attributes): ?string => Message::query()->whereKey($attributes['message_id'])->value('sender_profile_id'),
            'source' => MessageFlagSource::Auto,
            'rule' => MessageFlagRule::Profanity,
            'status' => MessageFlagStatus::Pending,
        ];
    }

    /** An automatic flag on this message. */
    public function on(Message $message, MessageFlagRule $rule = MessageFlagRule::Profanity): self
    {
        return $this->state([
            'message_id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'sender_profile_id' => $message->sender_profile_id,
            'rule' => $rule,
        ]);
    }
}
