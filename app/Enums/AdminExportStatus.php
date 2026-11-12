<?php

declare(strict_types=1);

namespace App\Enums;

/** A queued admin export (A09, P9.1a). */
enum AdminExportStatus: string
{
    case Queued = 'QUEUED';
    case Ready = 'READY';
    case Failed = 'FAILED';
    case Expired = 'EXPIRED';

    public function label(): string
    {
        return match ($this) {
            self::Queued => __('Being prepared'),
            self::Ready => __('Ready'),
            self::Failed => __('Failed'),
            self::Expired => __('Expired'),
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Ready => 'success',
            self::Failed => 'danger',
            self::Queued => 'warning',
            self::Expired => 'muted',
        };
    }
}
