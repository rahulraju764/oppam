<?php

declare(strict_types=1);

namespace App\Exceptions\Chat;

use App\Enums\ChatSendState;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A chat message the member may not send right now (PRD M07), with a member-facing reason.
 * `reason` is a stable key for tests and the UI. An upgrade refusal is a 403 (M07 acceptance:
 * "server returns 403 if bypassed"); sending too fast is a 429; a frozen thread is a 409.
 */
final class ChatRefused extends HttpException
{
    public const TOO_FAST = 'too_fast';

    private function __construct(public readonly string $reason, int $status, string $message)
    {
        parent::__construct($status, $message);
    }

    public static function because(ChatSendState $state): self
    {
        return new self(strtolower($state->value), match ($state) {
            ChatSendState::WaitForReply, ChatSendState::Upgrade => 403,
            default => 409,
        }, (string) $state->message());
    }

    /** `chat.max_per_minute` (30, PRD M07 send flow). */
    public static function tooFast(): self
    {
        return new self(self::TOO_FAST, 429, __('You are sending messages very quickly. Please wait a moment.'));
    }
}
