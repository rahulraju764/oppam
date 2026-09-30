<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Profile lifecycle (PRD §7.2, CLAUDE.md "Domain quick reference"):
 * DRAFT → PENDING_REVIEW → ACTIVE | REJECTED; also HIDDEN, SUSPENDED, DELETED.
 * Only ACTIVE profiles are searchable (R-M02-2).
 */
enum ProfileStatus: string
{
    case Draft = 'DRAFT';
    case PendingReview = 'PENDING_REVIEW';
    case Active = 'ACTIVE';
    case Rejected = 'REJECTED';
    case Hidden = 'HIDDEN';
    case Suspended = 'SUSPENDED';
    case Deleted = 'DELETED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::PendingReview => __('Pending review'),
            self::Active => __('Active'),
            self::Rejected => __('Rejected'),
            self::Hidden => __('Hidden'),
            self::Suspended => __('Suspended'),
            self::Deleted => __('Deleted'),
        };
    }

    public function isSearchable(): bool
    {
        return $this === self::Active;
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
