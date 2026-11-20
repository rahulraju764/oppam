<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The A09 numbers stored nightly in `report_snapshots` (P9.1a) — only numbers that ADD UP across
 * days (a flow inside the IST day, or a daily level read on its own day). Ratios are never stored:
 * they are computed from their parts. Numbers that don't add up over a range (WAU / MAU, reply
 * rates, medians, opens) are computed live by the report queries instead.
 */
enum ReportMetric: string
{
    // Growth
    case Signups = 'signups';
    case ProfilesApproved = 'profiles_approved';

    // Engagement
    case ActiveMembers = 'active_members';                 // members active that IST day (DAU)
    case LikesSent = 'likes_sent';
    case LikesMutual = 'likes_mutual';                     // likes that day that made a pair mutual
    case InterestsAfterLike = 'interests_after_like';      // interests that day to a profile the sender had liked
    case InterestsSent = 'interests_sent';
    case InterestsAccepted = 'interests_accepted';
    case InterestsDeclined = 'interests_declined';
    case ConversationsStarted = 'conversations_started';
    case Messages = 'messages';
    case Searches = 'searches';
    case SearchesZeroResults = 'searches_zero_results';
    case SearchFilterUsed = 'search_filter_used';           // dimension filter:<key>

    // Marketplace health (levels, read on their own day)
    case ActiveProfiles = 'active_profiles';
    case ActiveProfilesWithPhoto = 'active_profiles_with_photo';

    // Revenue (P9.1b) — paise, GST included; refunds only of the payment that paid the order (A02 rule)
    case RevenuePaidPaise = 'revenue_paid_paise';
    case RevenueRefundedPaise = 'revenue_refunded_paise';
    case OrdersPaid = 'orders_paid';
    case FirstPaidMembers = 'first_paid_members';
    case BrokerAttributedPaise = 'broker_attributed_paise';
    case PaidPlansEnded = 'paid_plans_ended';                 // stored on the day the plan ended, once settled (30 days on)
    case PaidPlansChurned = 'paid_plans_churned';

    // Trust & ops (P9.1b)
    case VerificationDecided = 'verification_decided';
    case VerificationWithinSla = 'verification_within_sla';
    case ModerationDecided = 'moderation_decided';
    case ModerationWithinSla = 'moderation_within_sla';
    case ReportsReceived = 'reports_received';
    case CasesDecided = 'cases_decided';
    case CasesBreached = 'cases_breached';
    case TicketsOpened = 'tickets_opened';
    case TicketsFirstResponded = 'tickets_first_responded';
    case TicketsFirstResponseInSla = 'tickets_first_response_in_sla';
    case TicketsResolved = 'tickets_resolved';

    public function kind(): string
    {
        return in_array($this, [self::ActiveProfiles, self::ActiveProfilesWithPhoto], true) ? 'stock' : 'flow';
    }

    /**
     * Days after its own day that a metric becomes final (P9.1b churn: "not renewed within 30 days").
     * Such a metric is only stored for days at least that old; the nightly run settles the day
     * SETTLE_DAYS back, so the newest days show "settles after 30 days" instead of a wrong number.
     */
    public function settlesAfterDays(): int
    {
        return in_array($this, [self::PaidPlansEnded, self::PaidPlansChurned], true) ? self::CHURN_DAYS : 0;
    }

    public const CHURN_DAYS = 30;

    /**
     * The table a captured metric is computed from (recorded only since P9.1a, owner 2026-11-12, and
     * pruned after its retention) — null for metrics computed from the app's own timestamps.
     */
    public function captureTable(): ?string
    {
        return match ($this) {
            self::ActiveMembers => 'member_activity_days',
            self::Searches, self::SearchesZeroResults, self::SearchFilterUsed => 'search_logs',
            default => null,
        };
    }

    public function isCaptured(): bool
    {
        return $this->captureTable() !== null;
    }

    /**
     * The first IST day a capture table still holds (null = nothing captured yet). A captured metric
     * is only (re)computed for days on or after it: before that day the source rows never existed or
     * have been pruned, and recomputing would overwrite the stored numbers with zeros.
     */
    public static function capturedSince(string $table): ?CarbonImmutable
    {
        $day = DB::table($table)->min('day');

        return $day === null ? null : CarbonImmutable::parse((string) $day, (string) config('oppam.display_timezone'))->startOfDay();
    }

    public function label(): string
    {
        return match ($this) {
            self::Signups => __('Signups'),
            self::ProfilesApproved => __('Profiles approved'),
            self::ActiveMembers => __('Active members (daily)'),
            self::LikesSent => __('Likes sent'),
            self::LikesMutual => __('Mutual likes'),
            self::InterestsAfterLike => __('Interests after a like'),
            self::InterestsSent => __('Interests sent'),
            self::InterestsAccepted => __('Interests accepted'),
            self::InterestsDeclined => __('Interests declined'),
            self::ConversationsStarted => __('Conversations started'),
            self::Messages => __('Messages'),
            self::Searches => __('Searches'),
            self::SearchesZeroResults => __('Searches with no results'),
            self::SearchFilterUsed => __('Searches using a filter'),
            self::ActiveProfiles => __('Live profiles'),
            self::ActiveProfilesWithPhoto => __('Live profiles with a photo'),
            self::RevenuePaidPaise => __('Paid'),
            self::RevenueRefundedPaise => __('Refunded'),
            self::OrdersPaid => __('Paid orders'),
            self::FirstPaidMembers => __('New paying members'),
            self::BrokerAttributedPaise => __('Through bureaus'),
            self::PaidPlansEnded => __('Paid plans ended'),
            self::PaidPlansChurned => __('Not renewed'),
            self::VerificationDecided => __('ID verifications decided'),
            self::VerificationWithinSla => __('ID verifications within SLA'),
            self::ModerationDecided => __('Moderation decisions'),
            self::ModerationWithinSla => __('Moderation within SLA'),
            self::ReportsReceived => __('Reports received'),
            self::CasesDecided => __('Abuse cases decided'),
            self::CasesBreached => __('Abuse cases past their SLA'),
            self::TicketsOpened => __('Support tickets opened'),
            self::TicketsFirstResponded => __('Tickets answered'),
            self::TicketsFirstResponseInSla => __('Tickets answered within SLA'),
            self::TicketsResolved => __('Tickets resolved'),
        };
    }

    public function definition(): string
    {
        return match ($this) {
            self::Signups => __('New member accounts registered (bureau-created client profiles excluded).'),
            self::ProfilesApproved => __('Profiles approved and published for the first time.'),
            self::ActiveMembers => __('Members who used the site that day (sum over days = member-days).'),
            self::LikesSent => __('Likes given (a like taken back the same day is not counted).'),
            self::LikesMutual => __('Likes that made a pair like each other.'),
            self::InterestsAfterLike => __('Interests sent to a profile the sender had liked earlier.'),
            self::InterestsSent => __('Interests sent, re-sends included.'),
            self::InterestsAccepted => __('Interests accepted.'),
            self::InterestsDeclined => __('Interests declined.'),
            self::ConversationsStarted => __('Chats opened by an accepted interest.'),
            self::Messages => __('Chat messages sent (system notes excluded).'),
            self::Searches => __('Searches run (first page only — "load more" is not another search).'),
            self::SearchesZeroResults => __('Searches that found no one.'),
            self::SearchFilterUsed => __('Searches that used each filter.'),
            self::ActiveProfiles => __('Approved, searchable profiles that day.'),
            self::ActiveProfilesWithPhoto => __('Live profiles with at least one approved photo.'),
            self::RevenuePaidPaise => __('Paid orders, GST included.'),
            self::RevenueRefundedPaise => __('Refunds processed, of the payment that paid the order.'),
            self::OrdersPaid => __('Orders paid.'),
            self::FirstPaidMembers => __('Members who paid for the first time.'),
            self::BrokerAttributedPaise => __('Paid orders that earned a bureau commission.'),
            self::PaidPlansEnded => __('Paid plans that ran to their end (an upgrade is not an end).'),
            self::PaidPlansChurned => __('Of those, the ones with no new paid plan within 30 days.'),
            self::VerificationDecided => __('ID verifications approved or rejected.'),
            self::VerificationWithinSla => __('Decided within the ID-verification SLA.'),
            self::ModerationDecided => __('Moderation items decided.'),
            self::ModerationWithinSla => __('Decided within the moderation SLA.'),
            self::ReportsReceived => __('Reports made by members.'),
            self::CasesDecided => __('Abuse cases closed with an outcome.'),
            self::CasesBreached => __('Abuse cases decided after their SLA.'),
            self::TicketsOpened => __('Support tickets opened.'),
            self::TicketsFirstResponded => __('Tickets that got their first answer.'),
            self::TicketsFirstResponseInSla => __('First answers given within the ticket SLA.'),
            self::TicketsResolved => __('Tickets resolved.'),
        };
    }
}
