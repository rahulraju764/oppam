<?php

declare(strict_types=1);

namespace App\Data\Notifications;

/** The /settings Notifications tab (M14): the matrix rows the member saved. Quiet hours are one window for everyone (A15). */
final readonly class NotificationSettingsData
{
    /** @param list<NotificationChoiceData> $choices */
    public function __construct(
        public array $choices,
    ) {}
}
