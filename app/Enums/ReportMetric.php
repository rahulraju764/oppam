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

    public function kind(): string
    {
        return in_array($this, [self::ActiveProfiles, self::ActiveProfilesWithPhoto], true) ? 'stock' : 'flow';
    }

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
        };
    }
}
