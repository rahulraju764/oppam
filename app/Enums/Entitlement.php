<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a plan allows (PRD §7.3). Values are stored in plan_features.entitlement and edited in A06.
 * A quota entitlement has a numeric limit (null = unlimited) that resets per period(); a flag
 * entitlement is simply on or off for the plan. Enforced in Actions via EntitlementService (P0.6).
 */
enum Entitlement: string
{
    case LikesPerDay = 'likes_per_day';
    case FavoritesTotal = 'favorites_total';
    case InterestsPerMonth = 'interests_per_month';
    case ContactViewsPerMonth = 'contact_views_per_month';
    case FreeRepliesPerConversation = 'free_replies_per_conversation';
    case ChatSend = 'chat_send';
    case SeeWhoLikedMe = 'see_who_liked_me';
    case SeeWhoViewedMe = 'see_who_viewed_me';
    case SearchHighlight = 'search_highlight';
    case TopPlacement = 'top_placement';

    public function period(): EntitlementPeriod
    {
        return match ($this) {
            self::LikesPerDay => EntitlementPeriod::Daily,
            self::InterestsPerMonth, self::ContactViewsPerMonth => EntitlementPeriod::Monthly,
            self::FavoritesTotal, self::FreeRepliesPerConversation => EntitlementPeriod::Lifetime,
            self::ChatSend, self::SeeWhoLikedMe, self::SeeWhoViewedMe, self::SearchHighlight, self::TopPlacement => EntitlementPeriod::None,
        };
    }

    public function isFlag(): bool
    {
        return $this->period() === EntitlementPeriod::None;
    }

    public function label(): string
    {
        return match ($this) {
            self::LikesPerDay => __('Likes per day'),
            self::FavoritesTotal => __('Favourites'),
            self::InterestsPerMonth => __('Interests per month'),
            self::ContactViewsPerMonth => __('Verified phone numbers per month'),
            self::FreeRepliesPerConversation => __('Free replies per conversation'),
            self::ChatSend => __('Send chat messages'),
            self::SeeWhoLikedMe => __('See who liked me'),
            self::SeeWhoViewedMe => __('See who viewed me'),
            self::SearchHighlight => __('Highlighted in search'),
            self::TopPlacement => __('Top placement in search'),
        };
    }
}
