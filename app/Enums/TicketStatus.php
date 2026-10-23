<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A support ticket's state (decisions 2026-10-23): OPEN (waiting for us) → PENDING_MEMBER (we
 * replied, waiting for them) → RESOLVED → CLOSED. A reply from the member re-opens anything but
 * CLOSED; a CLOSED ticket takes no more replies (the member opens a new one).
 */
enum TicketStatus: string
{
    case Open = 'OPEN';
    case PendingMember = 'PENDING_MEMBER';
    case Resolved = 'RESOLVED';
    case Closed = 'CLOSED';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::PendingMember => __('Waiting for member'),
            self::Resolved => __('Resolved'),
            self::Closed => __('Closed'),
        };
    }

    /** What the member sees for their own ticket. */
    public function memberLabel(): string
    {
        return match ($this) {
            self::Open => __('We’re looking into it'),
            self::PendingMember => __('We replied'),
            self::Resolved => __('Resolved'),
            self::Closed => __('Closed'),
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::PendingMember => 'verified',
            self::Resolved => 'success',
            self::Closed => 'muted',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Open || $this === self::PendingMember;
    }

    public function acceptsReplies(): bool
    {
        return $this !== self::Closed;
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
