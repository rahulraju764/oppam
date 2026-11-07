<?php

declare(strict_types=1);

namespace App\Enums;

/** A contact-form message in the A08 inbox. SPAM sits in its own tab and is pruned after 30 days. */
enum ContactStatus: string
{
    case New = 'NEW';
    case Assigned = 'ASSIGNED';
    case Replied = 'REPLIED';
    case Converted = 'CONVERTED';
    case Spam = 'SPAM';

    public function label(): string
    {
        return match ($this) {
            self::New => __('New'),
            self::Assigned => __('Assigned'),
            self::Replied => __('Replied'),
            self::Converted => __('Converted to ticket'),
            self::Spam => __('Spam'),
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::New => 'warning',
            self::Assigned => 'default',
            self::Replied, self::Converted => 'success',
            self::Spam => 'muted',
        };
    }

    /** Still waiting for someone on the team. */
    public function isOpen(): bool
    {
        return in_array($this, [self::New, self::Assigned], true);
    }
}
