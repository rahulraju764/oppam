<?php

declare(strict_types=1);

namespace App\Broadcasting;

/**
 * `presence-chat.{conversation}` (PRD §9.3): "online now" in a thread header and typing whispers.
 * Same rule as ChatChannel; the member is presented by profile code only (never phone, email or
 * ULID).
 */
final class PresenceChatChannel
{
    public function __construct(private readonly ChatChannel $chat) {}

    /** @return array{code: string}|false */
    public function join(mixed $user, string $conversation): array|false
    {
        $me = $this->chat->participant($user, $conversation);

        return $me === null ? false : ['code' => $me->code];
    }
}
