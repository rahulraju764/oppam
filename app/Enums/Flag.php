<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Feature flags (PRD A15 + §11A B.23 / B.24). Read with Feature::active(Flag::LikesEnabled).
 * A flag nobody has toggled uses its default: OFF for anything new or not yet released, ON for
 * the features that shipped and run today (likes, chat images, live updates) — so wiring their
 * switch never turns them off by itself (owner 2026-11-12). Toggled only through the
 * ToggleFeatureFlag Action (audited). push / boost are not built (v1.5): shown, never togglable.
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

    /** The state of a flag no admin has toggled (no row, or the seeded row). */
    public function defaultOn(): bool
    {
        return in_array($this, [self::RealtimeEnabled, self::LikesEnabled, self::ChatImages], true);
    }

    /** False = the feature behind it doesn't exist yet: the switch would do nothing, so it can't be toggled. */
    public function isBuilt(): bool
    {
        return ! in_array($this, [self::PushEnabled, self::BoostEnabled], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::RealtimeEnabled => __('Live updates'),
            self::RealtimePollingFallback => __('Polling fallback'),
            self::ChatImages => __('Photos in chat'),
            self::LikesEnabled => __('Likes'),
            self::BrokerSelfApply => __('Broker applications'),
            self::BrokerStaff => __('Bureau staff accounts'),
            self::BrokerBulkImport => __('Bureau bulk import'),
            self::BrokerClientInterestSending => __('Bureau interests for clients'),
            self::PaymentsRazorpayLive => __('Live payments'),
            self::PushEnabled => __('Push notifications'),
            self::BoostEnabled => __('Profile boost'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::RealtimeEnabled => __('Live updates over WebSockets (Reverb / Pusher). Off: no socket is opened; members see changes on the next page load.'),
            self::RealtimePollingFallback => __('Poll for updates when WebSockets are unavailable.'),
            self::ChatImages => __('Paid members can send photos in chat.'),
            self::LikesEnabled => __('Members can like profiles. Off: the like button is hidden and likes are refused.'),
            self::BrokerSelfApply => __('Brokers can apply publicly (admin KYC approval still required).'),
            self::BrokerStaff => __('Bureaus can add staff accounts.'),
            self::BrokerBulkImport => __('Bureaus can bulk-import profiles from Excel / CSV.'),
            self::BrokerClientInterestSending => __('Bureaus can send interests for managed profiles.'),
            self::PaymentsRazorpayLive => __('Use live Razorpay keys (test keys when off).'),
            self::PushEnabled => __('Browser push notifications — not built yet (v1.5).'),
            self::BoostEnabled => __('Profile boost add-on — not built yet (v1.5).'),
        };
    }
}
