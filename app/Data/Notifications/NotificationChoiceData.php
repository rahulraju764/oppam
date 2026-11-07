<?php

declare(strict_types=1);

namespace App\Data\Notifications;

use App\Enums\NotificationEvent;

/** One row of the /settings notification matrix (M14): which channels the member keeps for an event. */
final readonly class NotificationChoiceData
{
    public function __construct(
        public NotificationEvent $event,
        public bool $inApp,
        public bool $email,
        public bool $sms,
    ) {}
}
