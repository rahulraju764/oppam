<?php

declare(strict_types=1);

namespace App\Notifications\Broker;

use App\Data\Notifications\NotificationContentData;
use App\Enums\NotificationEvent;
use App\Notifications\Support\MemberNotification;

/**
 * §11A B.10 "commission earned" to the bureau Owner (in-app + live toast): the profile code and
 * the amount only — never the member's name or details (R-M13-7).
 */
final class CommissionEarned extends MemberNotification
{
    public function __construct(
        public readonly string $profileCode,
        public readonly string $amount,
    ) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::CommissionEarned;
    }

    public function content(object $notifiable): NotificationContentData
    {
        return new NotificationContentData(
            title: __('Commission earned: :amount', ['amount' => $this->amount]),
            body: __('Profile :code bought a plan. The commission is in your next payout.', ['code' => $this->profileCode]),
            url: route('broker.referrals'),
        );
    }
}
