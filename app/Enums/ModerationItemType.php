<?php

declare(strict_types=1);

namespace App\Enums;

/** The A04 moderation queues an item belongs to (PRD §11 A04). */
enum ModerationItemType: string
{
    case ProfileNew = 'PROFILE_NEW';     // a submitted profile (R-M02-2)
    case ProfileEdit = 'PROFILE_EDIT';   // edited text fields of a live profile (R-M02-4, P1.5)
    case Photo = 'PHOTO';                // an uploaded photo (M11, P1.4)

    public function label(): string
    {
        return match ($this) {
            self::ProfileNew => __('New profile'),
            self::ProfileEdit => __('Edited fields'),
            self::Photo => __('Photo'),
        };
    }

    /** The `queue` name carried by AdminQueueCountChanged on `admin.queues` (PRD §9.4). */
    public function queueKey(): string
    {
        return match ($this) {
            self::ProfileNew => 'moderation.profiles',
            self::ProfileEdit => 'moderation.edits',
            self::Photo => 'moderation.photos',
        };
    }
}
