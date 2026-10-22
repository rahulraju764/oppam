<?php

declare(strict_types=1);

namespace App\Notifications\Verification;

use App\Data\Notifications\NotificationContentData;
use App\Enums\NotificationEvent;
use App\Notifications\Support\MemberNotification;

/** A05 approve → "Verified badge … + notification" (M08 `verification_approved`, mandatory). */
final class VerificationApprovedNotification extends MemberNotification
{
    public function event(): NotificationEvent
    {
        return NotificationEvent::VerificationApproved;
    }

    public function content(object $notifiable): NotificationContentData
    {
        return new NotificationContentData(
            title: __('Your profile is verified'),
            body: __('We checked your ID. The Verified badge now shows on your profile and in search results.'),
            url: route('member.verification'),
        );
    }
}
