<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a member may write in a conversation right now (PRD M07), and why not — decided by
 * ChatAccess on the server; the composer only shows it.
 *  - CAN_SEND: a paid plan with chat_send, or a Free member's one reply not yet used (R-M07-3);
 *  - WAIT_FOR_REPLY: Free, and the other member hasn't written yet (a free message is a REPLY);
 *  - UPGRADE: Free, and the free reply in this conversation is used (or the plan allows none);
 *  - FROZEN: the thread is frozen (block, moderation — R-M07-5): readable, nobody writes;
 *  - UNAVAILABLE: the other member's profile is gone (deleted, suspended) or the pair is no
 *    longer connected (R-M07-1);
 *  - MANAGED: the other profile is run by a registered bureau and not claimed yet — nobody
 *    writes until the client joins (§11A B.6, R-M13-34).
 */
enum ChatSendState: string
{
    case CanSend = 'CAN_SEND';
    case WaitForReply = 'WAIT_FOR_REPLY';
    case Upgrade = 'UPGRADE';
    case Frozen = 'FROZEN';
    case Unavailable = 'UNAVAILABLE';
    case Managed = 'MANAGED';

    public function canSend(): bool
    {
        return $this === self::CanSend;
    }

    /** Member-facing reason the composer is locked (null when it isn't). */
    public function message(): ?string
    {
        return match ($this) {
            self::CanSend => null,
            self::WaitForReply => __('You can reply once for free when they write to you. Upgrade to start the conversation.'),
            self::Upgrade => __('You have used your free reply in this conversation. Upgrade to chat.'),
            self::Frozen => __('This conversation is closed for new messages.'),
            self::Unavailable => __('This member is no longer available.'),
            self::Managed => __('This profile is managed by a registered bureau. They will contact you or you can reach them after the client joins.'),
        };
    }
}
