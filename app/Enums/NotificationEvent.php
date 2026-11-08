<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every member notification type (PRD §10 M08 table) = `notification_preferences.event` and the
 * `notifications.type` column. Each knows its filter group on /notifications, its default
 * channels (used when the member has no preference row) and whether it is transactional or
 * safety mail that can't be switched off (R-M08-3).
 */
enum NotificationEvent: string
{
    case InterestReceived = 'interest_received';
    case InterestAccepted = 'interest_accepted';
    case InterestDeclined = 'interest_declined';
    case NewMessage = 'new_message';
    case ProfileLiked = 'profile_liked';
    case MutualLike = 'mutual_like';
    case ProfileViewed = 'profile_viewed';
    case PhotoRequest = 'photo_request';
    case HoroscopeRequest = 'horoscope_request';
    case DailyMatchesReady = 'daily_matches_ready';
    case SavedSearchAlert = 'saved_search_alert';
    case ProfileApproved = 'profile_approved';
    case ProfileRejected = 'profile_rejected';
    case VerificationApproved = 'verification_approved';
    case VerificationRejected = 'verification_rejected';
    case PaymentSuccess = 'payment_success';
    case RefundIssued = 'refund_issued';
    case PlanExpiring = 'plan_expiring';
    case PlanExpired = 'plan_expired';
    case AccountStatus = 'account_status';
    /** Mobile / email / password changed, a reset link used (M14, P8.2b): in-app + email, never off. */
    case AccountSecurity = 'account_security';
    case AdminAnnouncement = 'admin_announcement';
    case SafetyWarning = 'safety_warning';
    /** A bureau Owner earned commission (§11A B.10, P7.2): in-app only. */
    case CommissionEarned = 'commission_earned';
    /** A04 approved / rejected a bureau-managed profile (§11A B.15, P7.3): in-app only, to bureau logins. */
    case ManagedProfileReviewed = 'managed_profile_reviewed';
    /** Client follow-ups due today (§11A B.15 FollowUpDue, 08:30 IST, P7.3): in-app only, to bureau logins. */
    case FollowUpDue = 'follow_up_due';
    /** A managed client claimed their profile (§11A B.6 / B.15 ProfileClaimed, P7.4): in-app only, to the Owner and assignee. */
    case ManagedProfileClaimed = 'managed_profile_claimed';
    /** A payout to the bureau was sent (§11A B.15 PayoutPaid, P7.8): in-app + email, to the Owner. */
    case PayoutPaid = 'payout_paid';

    /** The /notifications filter this event belongs to. */
    public function group(): NotificationGroup
    {
        return match ($this) {
            self::InterestReceived, self::InterestAccepted, self::InterestDeclined,
            self::PhotoRequest, self::HoroscopeRequest => NotificationGroup::Interests,
            self::ProfileLiked, self::MutualLike => NotificationGroup::Likes,
            self::NewMessage => NotificationGroup::Messages,
            self::ProfileViewed => NotificationGroup::Views,
            self::DailyMatchesReady, self::SavedSearchAlert => NotificationGroup::Matches,
            default => NotificationGroup::System,
        };
    }

    /** M08 table defaults: [in-app, email, sms]. */
    public function defaultInApp(): bool
    {
        return true;
    }

    public function defaultEmail(): bool
    {
        return match ($this) {
            // digests (P8.3) or in-app only
            self::InterestDeclined, self::ProfileLiked, self::MutualLike, self::ProfileViewed,
            self::CommissionEarned, self::ManagedProfileReviewed, self::FollowUpDue, self::ManagedProfileClaimed => false,
            self::NewMessage => true,   // the offline digest only (P4.2, SendChatDigests) — never one mail per message
            default => true,
        };
    }

    public function defaultSms(): bool
    {
        return in_array($this, [
            self::InterestReceived, self::InterestAccepted, self::ProfileApproved, self::ProfileRejected,
            self::VerificationApproved, self::VerificationRejected, self::PaymentSuccess, self::PlanExpiring,
            self::PlanExpired, self::SafetyWarning,
        ], true);
    }

    /** Transactional / safety: the member can't switch these off (R-M08-3). */
    public function isMandatory(): bool
    {
        return in_array($this, [
            self::ProfileApproved, self::ProfileRejected, self::VerificationApproved, self::VerificationRejected,
            self::PaymentSuccess, self::RefundIssued, self::PlanExpired, self::AccountStatus, self::AccountSecurity, self::SafetyWarning,
        ], true);
    }

    public function icon(): string
    {
        return $this->group()->icon();
    }

    /** Does this event ever go out on this channel ('in_app' | 'email' | 'sms')? A member may switch it off, never add it. */
    public function usesChannel(string $channel): bool
    {
        return match ($channel) {
            'in_app' => $this->defaultInApp(),
            'email' => $this->defaultEmail(),
            'sms' => $this->defaultSms(),
            default => false,
        };
    }

    /** Sent to members (shown in the /settings matrix); the rest go to bureau logins only. */
    public function isForMembers(): bool
    {
        return ! in_array($this, [
            self::CommissionEarned, self::ManagedProfileReviewed, self::FollowUpDue, self::ManagedProfileClaimed, self::PayoutPaid,
        ], true);
    }

    /**
     * The /settings matrix rows (M14), in the /notifications filter order. A member can switch OFF
     * a channel the event uses (defaultEmail / defaultSms) but never add one (decisions 2026-11-07):
     * no per-like email, no SMS without a DLT template.
     *
     * @return list<self>
     */
    public static function forMemberSettings(): array
    {
        $order = array_flip(array_map(fn (NotificationGroup $g): string => $g->value, NotificationGroup::cases()));
        $events = array_values(array_filter(self::cases(), fn (self $e): bool => $e->isForMembers()));
        usort($events, fn (self $a, self $b): int => $order[$a->group()->value] <=> $order[$b->group()->value]);

        return $events;
    }

    public function label(): string
    {
        return match ($this) {
            self::InterestReceived => __('Someone sends me an interest'),
            self::InterestAccepted => __('My interest is accepted'),
            self::InterestDeclined => __('My interest is declined'),
            self::NewMessage => __('New chat messages (email: a summary while I’m away)'),
            self::ProfileLiked => __('Someone likes my profile'),
            self::MutualLike => __('A like becomes mutual'),
            self::ProfileViewed => __('Someone views my profile'),
            self::PhotoRequest => __('Photo requests'),
            self::HoroscopeRequest => __('Horoscope requests'),
            self::DailyMatchesReady => __('My daily matches are ready'),
            self::SavedSearchAlert => __('New profiles for my saved searches'),
            self::ProfileApproved => __('My profile is approved'),
            self::ProfileRejected => __('My profile needs changes'),
            self::VerificationApproved => __('My ID verification is approved'),
            self::VerificationRejected => __('My ID verification needs another try'),
            self::PaymentSuccess => __('Payment received'),
            self::RefundIssued => __('Refund issued'),
            self::PlanExpiring => __('My plan is about to end'),
            self::PlanExpired => __('My plan has ended'),
            self::AccountStatus => __('Account notices'),
            self::AccountSecurity => __('Changes to my mobile, email or password'),
            self::AdminAnnouncement => __('Offers and announcements from Oppam'),
            self::SafetyWarning => __('Safety warnings'),
            self::CommissionEarned => __('Commission earned'),
            self::ManagedProfileReviewed => __('Client profile reviewed'),
            self::FollowUpDue => __('Follow-ups due'),
            self::ManagedProfileClaimed => __('Client claimed their profile'),
            self::PayoutPaid => __('Payout sent'),
        };
    }
}
