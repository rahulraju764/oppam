<?php

declare(strict_types=1);

namespace App\Exceptions\Chat;

use App\Enums\ChatSendState;
use App\Enums\SafetyRule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A chat message the member may not send right now (PRD M07), with a member-facing reason.
 * `reason` is a stable key for tests and the UI. An upgrade refusal is a 403 (M07 acceptance:
 * "server returns 403 if bypassed"); sending too fast is a 429; a frozen thread is a 409.
 */
final class ChatRefused extends HttpException
{
    public const TOO_FAST = 'too_fast';

    public const IMAGE_PLAN = 'image_plan';

    public const CANNOT_UNSEND = 'cannot_unsend';

    public const ICEBREAKER = 'icebreaker';

    public const BLOCKED = 'blocked';

    public const PAUSED = 'paused';

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

    /** M07: image messages are for paid plans; a Free member's free reply is text only. */
    public static function imagesNeedPlan(): self
    {
        return new self(self::IMAGE_PLAN, 403, __('Sending photos is part of the paid plans. Upgrade to share photos.'));
    }

    /** P4.2: only the sender, only within `chat.unsend_window_minutes`, only once. */
    public static function cannotUnsend(): self
    {
        return new self(self::CANNOT_UNSEND, 409, __('This message can no longer be unsent.'));
    }

    /** P4.2: an icebreaker is a FIRST message only, and must be one of the A11 list. */
    public static function icebreakerUnavailable(): self
    {
        return new self(self::ICEBREAKER, 409, __('Icebreakers are for your first message.'));
    }

    /** A13 rules editor: a BLOCK rule matched — nothing was stored; the member may edit and send again. */
    public static function blocked(SafetyRule $rule): self
    {
        return new self(self::BLOCKED, 422, __('This message can’t be sent: :reason. Please edit it and try again.', ['reason' => $rule->refusal()]));
    }

    /** A13: a moderator paused everything this member sends (EngagementRestrictions). */
    public static function paused(string $until): self
    {
        return new self(self::PAUSED, 403, __('Sending likes, interests and messages is paused on your account until :time after a safety review. If you think this is a mistake, please contact support.', ['time' => $until]));
    }

    /** `chat.max_per_minute` (30, PRD M07 send flow). */
    public static function tooFast(): self
    {
        return new self(self::TOO_FAST, 429, __('You are sending messages very quickly. Please wait a moment.'));
    }
}
