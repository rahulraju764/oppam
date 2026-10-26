<?php

declare(strict_types=1);

namespace App\Queries\Broker;

use App\Enums\AuditActorType;
use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Models\AuditLog;
use App\Models\Broker;
use App\Models\BrokerStaff;
use App\Models\ModerationItem;
use App\Models\Profile;
use Carbon\CarbonImmutable;

/**
 * The Owner's monthly performance report (§11A B.8): per staff login of the bureau, for one IST
 * calendar month — profiles created and imported (`created_by_user_id`), A04 approvals and
 * rejections of the profiles they created (decided that month) and the rejection rate, interests
 * handled for clients and follow-ups completed (from the audit log). `created_by_user_id` drives
 * reports only, never payouts. Five grouped queries for the whole bureau, whatever its size.
 */
final class StaffPerformance
{
    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} the IST month as a UTC range */
    public static function range(string $month): array
    {
        $tz = (string) config('oppam.display_timezone');
        $start = preg_match('/^\d{4}-\d{2}$/', $month) === 1
            ? (CarbonImmutable::createFromFormat('!Y-m', $month, $tz) ?: CarbonImmutable::now($tz)->startOfMonth())
            : CarbonImmutable::now($tz)->startOfMonth();

        return [$start->startOfMonth()->utc(), $start->endOfMonth()->utc()];
    }

    public static function currentMonth(): string
    {
        return CarbonImmutable::now((string) config('oppam.display_timezone'))->format('Y-m');
    }

    /**
     * @return array<string, array{created: int, imported: int, approved: int, rejected: int, rejection_rate: int, interests: int, follow_ups: int}>
     *                                                                                                                                               staff user id => figures
     */
    public function forMonth(Broker $broker, string $month): array
    {
        [$from, $to] = self::range($month);
        $logins = BrokerStaff::query()->where('broker_id', $broker->id)->pluck('user_id')->all();

        if ($logins === []) {
            return [];
        }

        $created = Profile::withTrashed()->whereIn('created_by_user_id', $logins)->whereBetween('created_at', [$from, $to])
            ->toBase()->selectRaw('created_by_user_id as uid, count(*) as total, sum(case when import_batch_id is null then 0 else 1 end) as imported')
            ->groupBy('created_by_user_id')->get()->keyBy('uid');

        $decisions = ModerationItem::query()
            ->join('profiles', 'profiles.id', '=', 'moderation_items.profile_id')
            ->whereIn('profiles.created_by_user_id', $logins)
            ->where('moderation_items.type', ModerationItemType::ProfileNew->value)
            ->whereNotNull('moderation_items.decided_by_admin_id')
            ->whereBetween('moderation_items.decided_at', [$from, $to])
            ->whereIn('moderation_items.status', [ModerationStatus::Approved->value, ModerationStatus::Rejected->value])
            ->toBase()->selectRaw('profiles.created_by_user_id as uid, moderation_items.status as status, count(*) as total')
            ->groupBy('profiles.created_by_user_id', 'moderation_items.status')->get();

        $audited = AuditLog::query()
            ->where('actor_type', AuditActorType::User->value)->whereIn('actor_id', $logins)
            ->whereIn('action', ['broker.interest_accepted', 'broker.interest_declined', 'broker.interest_sent', 'broker.follow_up_completed'])
            ->whereBetween('created_at', [$from, $to])
            ->toBase()->selectRaw('actor_id as uid, action, count(*) as total')
            ->groupBy('actor_id', 'action')->get();

        $report = [];
        foreach ($logins as $uid) {
            $mine = fn (iterable $rows, ?string $key = null, ?string $value = null): int => (int) collect($rows)
                ->filter(fn (object $r): bool => (string) $r->uid === (string) $uid && ($key === null || in_array((string) $r->{$key}, (array) $value, true)))
                ->sum('total');

            $approved = $mine($decisions, 'status', ModerationStatus::Approved->value);
            $rejected = $mine($decisions, 'status', ModerationStatus::Rejected->value);

            $report[(string) $uid] = [
                'created' => (int) ($created[$uid]->total ?? 0),
                'imported' => (int) ($created[$uid]->imported ?? 0),
                'approved' => $approved,
                'rejected' => $rejected,
                'rejection_rate' => $approved + $rejected > 0 ? (int) round($rejected * 100 / ($approved + $rejected)) : 0,
                'interests' => $mine($audited, 'action', 'broker.interest_accepted')
                    + $mine($audited, 'action', 'broker.interest_declined') + $mine($audited, 'action', 'broker.interest_sent'),
                'follow_ups' => $mine($audited, 'action', 'broker.follow_up_completed'),
            ];
        }

        return $report;
    }
}
