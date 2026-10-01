<?php

declare(strict_types=1);

namespace App\Enums;

/** A profile photo's moderation state (M11): only APPROVED photos are ever shown to others. */
enum PhotoStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Awaiting review'),
            self::Approved => __('Approved'),
            self::Rejected => __('Not approved'),
        };
    }

    /** <x-ui.badge> variant (oppam-ui-standards: map enums to classes, never concatenate). */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected => 'muted',
        };
    }
}
