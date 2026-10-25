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
    case PlanExpiring = 'plan_expiring';
    case PlanExpired = 'plan_expired';
    case AccountStatus = 'account_status';
    case AdminAnnouncement = 'admin_announcement';
    case SafetyWarning = 'safety_warning';
    /** A bureau Owner earned commission (§11A B.10, P7.2): in-app only. */
    case CommissionEarned = 'commission_earned';
    /** A04 approved / rejected a bureau-managed profile (§11A B.15, P7.3): in-app only, to bureau logins. */
    case ManagedProfileReviewed = 'managed_profile_reviewed';
    /** Client follow-ups due today (§11A B.15 FollowUpDue, 08:30 IST, P7.3): in-app only, to bureau logins. */
    case FollowUpDue = 'follow_up_due';

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
            self::CommissionEarned, self::ManagedProfileReviewed, self::FollowUpDue => false,
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
            self::PaymentSuccess, self::PlanExpired, self::AccountStatus, self::SafetyWarning,
        ], true);
    }

    public function icon(): string
    {
        return $this->group()->icon();
    }
}
