<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why an admin action took a member's waiting moderation items out of the A04 queues (stored as
 * moderation_items.reason_category). The matching undo — reactivate / restore — puts exactly
 * those items back.
 */
enum ModerationHold: string
{
    case MemberSuspended = 'MEMBER_SUSPENDED';
    case MemberDeleted = 'MEMBER_DELETED';

    /** The marker for an item that was ESCALATED when it was held (released back to escalated). */
    public function escalated(): string
    {
        return $this->value.'_ESC';
    }
}
