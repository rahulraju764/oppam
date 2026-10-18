<?php

declare(strict_types=1);

namespace App\Data\Chat;

use Carbon\CarbonImmutable;

/**
 * One message as a moderator sees it on an A13 flag review, after the audited reveal: who wrote it
 * (profile code; SYSTEM messages read "Oppam"), the stored text (already masked where ContentSafety
 * masked it; an image reads "Photo"), its time in IST and whether it is the flagged one.
 */
final readonly class ModeratedMessageData
{
    public function __construct(
        public string $id,
        public string $senderLabel,
        public string $text,
        public CarbonImmutable $at,
        public bool $isFlagged,
        public bool $isImage,
        public bool $gone,
    ) {}
}
