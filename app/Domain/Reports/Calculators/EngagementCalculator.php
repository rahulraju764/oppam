<?php

declare(strict_types=1);

namespace App\Domain\Reports\Calculators;

use App\Data\Reports\SnapshotRow;
use App\Domain\Reports\DayWindow;
use App\Enums\InterestStatus;
use App\Enums\MessageType;
use App\Enums\PlanCode;
use App\Enums\ReportMetric;
use Illuminate\Support\Facades\DB;

/**
 * A09 engagement numbers for one IST day (P9.1a), each a count that adds up across days:
 * active members (from member_activity_days), likes and mutual likes, interests sent / accepted /
 * declined by the SENDER's gender and plan tier at that moment (the subscription running then;
 * none = Free), interests sent to a profile the sender had liked, conversations started,
 * messages, and searches (with no results, by filter key — from the anonymous search log).
 */
final class EngagementCalculator
{
    /** @return list<SnapshotRow> */
    public function day(DayWindow $day): array
    {
        $rows = [new SnapshotRow(ReportMetric::ActiveMembers, DB::table('member_activity_days')->where('day', $day->date)->count())];

        $likes = DB::table('likes')->where('created_at', '>=', $day->from)->where('created_at', '<', $day->to);
        $rows[] = new SnapshotRow(ReportMetric::LikesSent, (clone $likes)->count());
        $rows[] = new SnapshotRow(ReportMetric::LikesMutual, (clone $likes)->whereExists(fn ($q) => $q->selectRaw('1')->from('likes as back')
            ->whereColumn('back.from_profile_id', 'likes.to_profile_id')->whereColumn('back.to_profile_id', 'likes.from_profile_id')
            // The EARLIER like of the pair (ties in the same second broken by id) is the one that came first:
            // only the later one "made it mutual", so a pair counts once.
            ->where(fn ($w) => $w->whereColumn('back.created_at', '<', 'likes.created_at')
                ->orWhere(fn ($tie) => $tie->whereColumn('back.created_at', '=', 'likes.created_at')->whereColumn('back.id', '<', 'likes.id'))))->count());

        foreach ([InterestStatus::Pending->value => ReportMetric::InterestsSent, InterestStatus::Accepted->value => ReportMetric::InterestsAccepted,
            InterestStatus::Declined->value => ReportMetric::InterestsDeclined] as $status => $metric) {
            array_push($rows, ...$this->interests($day, $status, $metric));
        }

        $rows[] = new SnapshotRow(ReportMetric::InterestsAfterLike, DB::table('interest_events')
            ->join('interests', 'interests.id', '=', 'interest_events.interest_id')
            ->where('interest_events.status', InterestStatus::Pending->value)
            ->where('interest_events.created_at', '>=', $day->from)->where('interest_events.created_at', '<', $day->to)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('likes')
                ->whereColumn('likes.from_profile_id', 'interests.from_profile_id')->whereColumn('likes.to_profile_id', 'interests.to_profile_id')
                ->whereColumn('likes.created_at', '<=', 'interest_events.created_at'))
            ->count());

        $rows[] = new SnapshotRow(ReportMetric::ConversationsStarted, DB::table('conversations')
            ->where('created_at', '>=', $day->from)->where('created_at', '<', $day->to)->count());
        $rows[] = new SnapshotRow(ReportMetric::Messages, DB::table('messages')->where('type', '!=', MessageType::System->value)
            ->where('created_at', '>=', $day->from)->where('created_at', '<', $day->to)->count());

        array_push($rows, ...$this->searches($day));

        return $rows;
    }

    /** @return list<SnapshotRow> one interest event status, in total and by the sender's gender and tier then */
    private function interests(DayWindow $day, string $status, ReportMetric $metric): array
    {
        $tier = DB::table('subscriptions')->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->whereColumn('subscriptions.profile_id', 'interests.from_profile_id')
            ->whereColumn('subscriptions.starts_at', '<=', 'interest_events.created_at')
            ->whereColumn('subscriptions.ends_at', '>', 'interest_events.created_at')
            ->orderByDesc('subscriptions.starts_at')->limit(1)->select('plans.code');

        $events = DB::table('interest_events')
            ->join('interests', 'interests.id', '=', 'interest_events.interest_id')
            ->join('profiles', 'profiles.id', '=', 'interests.from_profile_id')
            ->where('interest_events.status', $status)
            ->where('interest_events.created_at', '>=', $day->from)->where('interest_events.created_at', '<', $day->to)
            ->selectRaw('profiles.gender as gender, COUNT(*) as n')
            ->selectSub($tier, 'tier')
            ->groupBy('profiles.gender', 'tier')
            ->get();

        $rows = [new SnapshotRow($metric, (int) $events->sum('n'))];
        foreach ($events->groupBy(fn (object $r): string => (string) $r->gender) as $gender => $group) {
            $rows[] = new SnapshotRow($metric, (int) $group->sum('n'), 'gender:'.$gender);
        }
        foreach ($events->groupBy(fn (object $r): string => (string) ($r->tier ?? PlanCode::Free->value)) as $tier => $group) {
            $rows[] = new SnapshotRow($metric, (int) $group->sum('n'), 'tier:'.$tier);
        }

        return $rows;
    }

    /** @return list<SnapshotRow> */
    private function searches(DayWindow $day): array
    {
        $logs = DB::table('search_logs')->where('day', $day->date)->get(['filters', 'result_count']);
        $rows = [
            new SnapshotRow(ReportMetric::Searches, $logs->count()),
            new SnapshotRow(ReportMetric::SearchesZeroResults, $logs->where('result_count', 0)->count()),
        ];

        $byFilter = [];
        foreach ($logs as $log) {
            foreach ((array) json_decode((string) $log->filters, true) as $key) {
                if (is_string($key) && preg_match('/^[a-z_]{1,40}$/', $key) === 1) {
                    $byFilter[$key] = ($byFilter[$key] ?? 0) + 1;
                }
            }
        }
        foreach ($byFilter as $key => $n) {
            $rows[] = new SnapshotRow(ReportMetric::SearchFilterUsed, $n, 'filter:'.$key);
        }

        return $rows;
    }
}
