<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where an ID verification request is (PRD M09 / A05): waiting (PENDING, or ESCALATED to a super
 * admin), decided (APPROVED / REJECTED), REVOKED (an approval withdrawn by "force
 * re-verification", A03) or CANCELLED (closed by the system — the account was deleted).
 */
enum VerificationStatus: string
{
    case Pending = 'PENDING';
    case Escalated = 'ESCALATED';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Revoked = 'REVOKED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Waiting'),
            self::Escalated => __('Escalated'),
            self::Approved => __('Approved'),
            self::Rejected => __('Rejected'),
            self::Revoked => __('Revoked'),
            self::Cancelled => __('Cancelled'),
        };
    }

    /** @return 'default'|'verified'|'muted'|'success'|'warning' an <x-ui.badge> variant */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Pending => 'default',
            self::Escalated => 'warning',
            self::Approved => 'verified',
            self::Rejected, self::Revoked, self::Cancelled => 'muted',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Escalated;
    }

    /** @return list<self> */
    public static function open(): array
    {
        return [self::Pending, self::Escalated];
    }
}
