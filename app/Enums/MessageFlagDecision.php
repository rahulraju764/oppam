<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a moderator decided on a flagged message (PRD A13): dismiss, delete the message (tombstone
 * "removed by Oppam"), warn the sender, freeze the conversation, suspend the sender (the A03 flow),
 * or escalate (to a super admin; the A10 case arrives with P6.2). Each needs its own permission.
 */
enum MessageFlagDecision: string
{
    case Dismiss = 'DISMISS';
    case RemoveMessage = 'REMOVE_MESSAGE';
    case WarnSender = 'WARN_SENDER';
    case FreezeConversation = 'FREEZE';
    case SuspendSender = 'SUSPEND_SENDER';
    case Escalate = 'ESCALATE';

    public function label(): string
    {
        return match ($this) {
            self::Dismiss => __('Dismiss'),
            self::RemoveMessage => __('Delete message'),
            self::WarnSender => __('Warn sender'),
            self::FreezeConversation => __('Freeze conversation'),
            self::SuspendSender => __('Suspend sender'),
            self::Escalate => __('Escalate'),
        };
    }

    /** The admin permission this decision needs (config/admin-permissions.php). */
    public function permission(): string
    {
        return match ($this) {
            self::FreezeConversation => 'chat.freeze',
            self::SuspendSender => 'members.suspend',
            default => 'chat.moderate',
        };
    }

    /** The flag's status after this decision. */
    public function resultingStatus(): MessageFlagStatus
    {
        return match ($this) {
            self::Dismiss => MessageFlagStatus::Dismissed,
            self::Escalate => MessageFlagStatus::Escalated,
            default => MessageFlagStatus::Actioned,
        };
    }
}
