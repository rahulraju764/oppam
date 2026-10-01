<?php

declare(strict_types=1);

namespace App\Data\Media;

use App\Enums\PhotoStatus;

/**
 * One photo as a particular viewer may see it (M11). For a viewer without permission every URL
 * is the blurred conversion; `status` is only filled for the owner (pending / rejected labels).
 * The uuid identifies the photo in owner actions; never the auto-increment id.
 */
final readonly class PhotoView
{
    public function __construct(
        public string $uuid,
        public string $thumbUrl,
        public string $cardUrl,
        public string $fullUrl,
        public bool $blurred,
        public bool $processing,
        public ?string $caption,
        public ?PhotoStatus $status,
        public ?string $rejectionReason,
    ) {}
}
