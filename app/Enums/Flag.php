<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Feature flags (PRD A15 + §11A B.23 / B.24). Read with Feature::active(Flag::LikesEnabled).
 * Every flag defaults to OFF: a feature is switched on only once its session has shipped and its
 * tests pass (CLAUDE.md "Don't enable a feature in production before its tests pass and its flag
 * is set"). Toggled only through the ToggleFeatureFlag Action (audited).
 */
enum Flag: string
{
    case RealtimeEnabled = 'realtime.enabled';
    case RealtimePollingFallback = 'realtime.polling_fallback';
    case ChatImages = 'chat.images';
    case LikesEnabled = 'likes.enabled';
    case BrokerSelfApply = 'broker.self_apply';
    case BrokerStaff = 'broker.staff';
    case BrokerBulkImport = 'broker.bulk_import';
    case BrokerClientInterestSending = 'broker.client_interest_sending';
    case PaymentsRazorpayLive = 'payments.razorpay_live';
    case PushEnabled = 'push.enabled';
    case BoostEnabled = 'boost.enabled';

    public function description(): string
    {
        return match ($this) {
            self::RealtimeEnabled => 'Live updates over WebSockets (Reverb / Pusher).',
            self::RealtimePollingFallback => 'Poll for updates when WebSockets are unavailable.',
            self::ChatImages => 'Allow image messages in chat.',
            self::LikesEnabled => 'Members can like profiles.',
            self::BrokerSelfApply => 'Brokers can apply publicly (admin KYC approval still required).',
            self::BrokerStaff => 'Bureaus can add staff accounts.',
            self::BrokerBulkImport => 'Bureaus can bulk-import profiles from Excel/CSV.',
            self::BrokerClientInterestSending => 'Bureaus can send interests for managed profiles.',
            self::PaymentsRazorpayLive => 'Use live Razorpay keys (test keys when off).',
            self::PushEnabled => 'Browser push notifications (v1.5).',
            self::BoostEnabled => 'Profile boost add-on (v1.5).',
        };
    }
}
