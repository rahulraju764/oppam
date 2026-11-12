<?php

declare(strict_types=1);

namespace App\Queries\Reports;

use App\Domain\Reports\ReportRange;
use App\Enums\MessageType;
use App\Enums\ModerationItemType;
use App\Enums\PhotoStatus;
use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Models\Profile;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The A09 numbers that can't be added up from daily snapshots (P9.1a), computed for the period
 * and cached 10 minutes: unique active members, the activation funnel, weekly cohorts,
 * conversation quality (reply rate, messages per conversation, typical first-reply time) and
 * notification delivery by channel. Each is a bounded, grouped query on indexed columns.
 */
final class LiveMetrics
{
    public const CACHE_SECONDS = 600;

    public const COHORT_WEEKS = 8;

    /** Members active at least once in the period (WAU for 7 days, MAU for 30). */
    public function uniqueActive(ReportRange $range): int
    {
        return (int) $this->cached('active', $range, fn (): int => DB::table('member_activity_days')
            ->whereBetween('day', [$range->from->toDateString(), $range->to->toDateString()])
            ->distinct()->count('user_id'));
    }

    /**
     * The activation funnel of members who REGISTERED in the period, as they are now.
     *
     * @return array<string, int> step label => members
     */
    public function funnel(ReportRange $range): array
    {
        /** @var array<string, int> */
        return $this->cached('funnel', $range, function () use ($range): array {
            $base = DB::table('users')->join('profiles', 'profiles.user_id', '=', 'users.id')
                ->where('users.role', UserRole::Member->value)
                ->where('users.created_at', '>=', $range->utcFrom())->where('users.created_at', '<', $range->utcTo());

            $photo = fn ($q) => $q->selectRaw('1')->from('media')->where('media.model_type', (new Profile)->getMorphClass())
                ->whereColumn('media.model_id', 'profiles.id')->where('media.collection_name', Profile::PHOTOS)
                ->where('media.moderation_status', '!=', PhotoStatus::Rejected->value);
            $submitted = fn ($q) => $q->selectRaw('1')->from('moderation_items')->whereColumn('moderation_items.profile_id', 'profiles.id')
                ->where('moderation_items.type', ModerationItemType::ProfileNew->value);

            return [
                __('Registered') => (clone $base)->count(),
                __('Phone verified') => (clone $base)->whereNotNull('users.phone_verified_at')->count(),
                __('Profile half complete') => (clone $base)->where('profiles.completeness', '>=', 50)->count(),
                __('Photo uploaded') => (clone $base)->whereExists($photo)->count(),
                __('Submitted for review') => (clone $base)->where(fn ($q) => $q->whereExists($submitted)->orWhereNotNull('profiles.published_at'))->count(),
                __('Approved') => (clone $base)->whereNotNull('profiles.published_at')->count(),
            ];
        });
    }

    /**
     * Weekly cohorts: members who registered in each of the period's last COHORT_WEEKS weeks
     * (Monday start, IST) and the share active in each week after.
     *
     * @return list<array{week: string, members: int, weeks: list<int|null>}> weeks = % active in week 1..8 (null = not over yet)
     */
    public function cohorts(ReportRange $range): array
    {
        /** @var list<array{week: string, members: int, weeks: list<int|null>}> */
        return $this->cached('cohorts', $range, function () use ($range): array {
            $tz = (string) config('oppam.display_timezone');
            $lastWeek = $range->to->startOfWeek(CarbonImmutable::MONDAY);
            $firstWeek = $range->from->startOfWeek(CarbonImmutable::MONDAY)->max($lastWeek->subWeeks(self::COHORT_WEEKS - 1));
            $today = CarbonImmutable::now($tz)->startOfDay();

            $cohorts = [];
            for ($week = $firstWeek; $week->lessThanOrEqualTo($lastWeek); $week = $week->addWeek()) {
                $members = DB::table('users')->where('role', UserRole::Member->value)
                    ->where('created_at', '>=', $week->utc())->where('created_at', '<', $week->addWeek()->utc());
                $size = (clone $members)->count();

                $active = $size === 0 ? [] : DB::table('member_activity_days')
                    ->whereIn('user_id', (clone $members)->select('id'))
                    ->where('day', '>=', $week->addWeek()->toDateString())
                    ->where('day', '<', $week->addWeeks(self::COHORT_WEEKS + 1)->toDateString())
                    ->selectRaw('FLOOR(DATEDIFF(day, ?) / 7) as wk, COUNT(DISTINCT user_id) as n', [$week->toDateString()])
                    ->groupBy('wk')->pluck('n', 'wk')->all();

                $weeks = [];
                for ($n = 1; $n <= self::COHORT_WEEKS; $n++) {
                    $weeks[] = $size === 0 || $week->addWeeks($n + 1)->greaterThan($today)
                        ? null : (int) round((int) ($active[$n] ?? 0) / $size * 100);
                }
                $cohorts[] = ['week' => $week->format('d M'), 'members' => $size, 'weeks' => $weeks];
            }

            return $cohorts;
        });
    }

    /**
     * Conversations opened in the period, as they are now: how many got a reply (both members
     * wrote), messages and the typical (median) minutes to the first reply.
     *
     * @return array{started: int, replied: int, messages: int, median_reply_minutes: int|null}
     */
    public function conversations(ReportRange $range): array
    {
        /** @var array{started: int, replied: int, messages: int, median_reply_minutes: int|null} */
        return $this->cached('conversations', $range, function () use ($range): array {
            $system = MessageType::System->value;
            $started = DB::table('conversations')
                ->where('created_at', '>=', $range->utcFrom())->where('created_at', '<', $range->utcTo())->count();

            // One pass over the period's messages: per conversation its first message, count and writers.
            $perThread = DB::table('messages')
                ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
                ->where('conversations.created_at', '>=', $range->utcFrom())->where('conversations.created_at', '<', $range->utcTo())
                ->where('messages.type', '!=', $system)
                ->groupBy('messages.conversation_id')
                ->selectRaw('messages.conversation_id, MIN(messages.id) as first_id, COUNT(*) as messages, COUNT(DISTINCT messages.sender_profile_id) as senders');

            $threads = DB::query()->fromSub($perThread, 't')->get(['messages', 'senders']);

            // The first answer from the OTHER member: joined once per conversation (index conversation_id, id).
            $replies = DB::query()->fromSub($perThread, 't')
                ->join('messages as first', 'first.id', '=', 't.first_id')
                ->join('messages as reply', fn ($j) => $j->on('reply.conversation_id', '=', 't.conversation_id')
                    ->whereColumn('reply.sender_profile_id', '!=', 'first.sender_profile_id')->where('reply.type', '!=', $system))
                ->groupBy('t.conversation_id', 'first.created_at')
                ->selectRaw('first.created_at as first_at, MIN(reply.created_at) as reply_at')
                ->get();

            $minutes = [];
            foreach ($replies as $r) {
                $minutes[] = (int) CarbonImmutable::parse((string) $r->first_at)->diffInMinutes(CarbonImmutable::parse((string) $r->reply_at));
            }
            sort($minutes);

            return [
                'started' => $started,
                'replied' => $threads->where('senders', '>=', 2)->count(),
                'messages' => (int) $threads->sum('messages'),
                'median_reply_minutes' => $minutes === [] ? null : $minutes[intdiv(count($minutes), 2)],
            ];
        });
    }

    /**
     * Outbound email / SMS by channel and in-app notifications for the period (delivery-log rows
     * are kept 180 days, so older periods show less).
     *
     * @return array<string, array{sent: int, delivered: int, opened: int|null, failed: int}>
     */
    public function notifications(ReportRange $range): array
    {
        /** @var array<string, array{sent: int, delivered: int, opened: int|null, failed: int}> */
        return $this->cached('notifications', $range, function () use ($range): array {
            $out = DB::table('outbound_messages')
                ->where('created_at', '>=', $range->utcFrom())->where('created_at', '<', $range->utcTo())
                ->groupBy('channel')
                ->selectRaw('channel, COUNT(*) as sent, COUNT(delivered_at) as delivered, COUNT(opened_at) as opened, SUM(CASE WHEN failed_at IS NOT NULL OR bounced_at IS NOT NULL THEN 1 ELSE 0 END) as failed')
                ->get();

            $inApp = DB::table('notifications')
                ->where('created_at', '>=', $range->utcFrom())->where('created_at', '<', $range->utcTo())
                ->selectRaw('COUNT(*) as sent, COUNT(read_at) as opened')->first();

            $result = [];
            foreach ($out as $row) {
                $channel = (string) $row->channel;
                $result[$channel] = ['sent' => (int) $row->sent, 'delivered' => (int) $row->delivered,
                    'opened' => $channel === 'email' ? (int) $row->opened : null, 'failed' => (int) $row->failed];
            }
            $result['in_app'] = ['sent' => (int) ($inApp->sent ?? 0), 'delivered' => (int) ($inApp->sent ?? 0), 'opened' => (int) ($inApp->opened ?? 0), 'failed' => 0];

            return $result;
        });
    }

    /** Saved-search alerts sent in the period (in-app rows; the email copy is the same alert). */
    public function savedSearchAlerts(ReportRange $range): int
    {
        return (int) $this->cached('alerts', $range, fn (): int => DB::table('notifications')->where('type', 'saved_search_alert')
            ->where('created_at', '>=', $range->utcFrom())->where('created_at', '<', $range->utcTo())->count());
    }

    /**
     * Live profiles nobody has sent an interest to in the last `$days` days (and live at least that
     * long) — the work queue for outreach. Read-only; 20 a page, oldest first.
     *
     * @return LengthAwarePaginator<int, Profile>
     */
    public function zeroInterest(int $days, int $page = 1): LengthAwarePaginator
    {
        return $this->zeroInterestQuery($days)->orderBy('published_at')->paginate(20, page: $page);
    }

    /**
     * The same list for an export: streamed in id order, 1,000 at a time (no OFFSET pages).
     *
     * @return iterable<Profile>
     */
    public function zeroInterestAll(int $days): iterable
    {
        return $this->zeroInterestQuery($days)->lazyById(1000);
    }

    public function zeroInterestCount(int $days): int
    {
        return $this->zeroInterestQuery($days)->count();
    }

    /** @return Builder<Profile> */
    private function zeroInterestQuery(int $days): Builder
    {
        $since = now()->subDays($days);

        return Profile::query()
            ->where('status', ProfileStatus::Active->value)
            ->where('published_at', '<', $since)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('interests')->whereColumn('interests.to_profile_id', 'profiles.id')
                ->where('interests.sent_at', '>=', $since))
            ->with('district:id,label')
            ->select(['id', 'code', 'first_name', 'gender', 'district_id', 'published_at', 'user_id']);
    }

    /** @param Closure(): mixed $compute */
    private function cached(string $kind, ReportRange $range, Closure $compute): mixed
    {
        return Cache::remember('reports:live:'.$kind.':'.$range->from->toDateString().':'.$range->to->toDateString(), self::CACHE_SECONDS, $compute);
    }
}
