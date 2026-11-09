<?php

declare(strict_types=1);

namespace App\Data\Comms;

use App\Enums\AnnouncementAudience;
use Carbon\CarbonImmutable;

/** An A14 announcement as the admin form sends it (P8.3a). Times entered in IST, stored UTC. */
final readonly class AnnouncementData
{
    public function __construct(
        public string $title,
        public string $body,
        public ?string $actionUrl,
        public ?string $actionText,
        public ?CarbonImmutable $startsAt,
        public ?CarbonImmutable $endsAt,
        public AnnouncementAudience $audience,
        public bool $isDismissible,
    ) {}
}
