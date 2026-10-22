<?php

declare(strict_types=1);

namespace App\Notifications\Verification;

use App\Data\Notifications\NotificationContentData;
use App\Enums\NotificationEvent;
use App\Notifications\Support\MemberNotification;

/**
 * A05 reject ("reasons → member guidance") or A03 "force re-verification" (the badge was taken
 * back) — M08 `verification_rejected`, mandatory. The guidance is the reason's member message and
 * the officer's note; it never names the officer or the flags.
 */
final class VerificationRejectedNotification extends MemberNotification
{
    public function __construct(
        public readonly string $guidance,
        public readonly bool $revoked = false,
    ) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::VerificationRejected;
    }

    public function content(object $notifiable): NotificationContentData
    {
        return new NotificationContentData(
            title: $this->revoked ? __('Please verify your ID again') : __('We couldn’t verify your ID'),
            body: $this->guidance,
            url: route('member.verification'),
        );
    }
}
