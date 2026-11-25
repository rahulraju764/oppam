<?php

declare(strict_types=1);

namespace App\Queries\Reports;

use App\Domain\Reports\ReportRange;
use App\Enums\ModerationStatus;
use App\Enums\SettingKey;
use App\Queries\Reports\Concerns\SqlMedian;
use App\Support\Facades\Settings;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The A09 trust & ops numbers that aren't daily sums (P9.1b), cached 10 minutes: typical (median)
 * time to a decision / first answer for the period, and moderator quality — per admin who decided
 * moderation items: decisions, outcome split, typical minutes and share within SLA. The admin's
 * NAME only (never their email); TrustReport shows the per-admin rows only to `reports.staff`.
 */
final class LiveOps
{
    public const CACHE_SECONDS = 600;

    /** @return array{moderation_minutes: int|null, verification_hours: int|null, ticket_hours: int|null} */
    public function medians(ReportRange $range): array
    {
        /** @var array{moderation_minutes: int|null, verification_hours: int|null, ticket_hours: int|null} */
        return $this->cached('medians', $range, fn (): array => [
            'moderation_minutes' => SqlMedian::of(DB::table('moderation_items')->whereNotNull('decided_at')
                ->where('decided_at', '>=', $range->utcFrom())->where('decided_at', '<', $range->utcTo())
                ->selectRaw('TIMESTAMPDIFF(MINUTE, submitted_at, decided_at) as v')),
            'verification_hours' => SqlMedian::of(DB::table('verification_requests')->whereNotNull('decided_at')
                ->where('decided_at', '>=', $range->utcFrom())->where('decided_at', '<', $range->utcTo())
                ->selectRaw('TIMESTAMPDIFF(HOUR, submitted_at, decided_at) as v')),
            'ticket_hours' => SqlMedian::of(DB::table('support_tickets')->whereNotNull('first_responded_at')
                ->where('first_responded_at', '>=', $range->utcFrom())->where('first_responded_at', '<', $range->utcTo())
                ->selectRaw('TIMESTAMPDIFF(HOUR, created_at, first_responded_at) as v')),
        ]);
    }

    /**
     * Moderator quality for the period, busiest first — counts as SQL aggregates and the typical minutes as one
     * grouped median (P9.3c), never the period's rows in PHP.
     *
     * @return list<array{name: string, decided: int, approved: int, rejected: int, changes: int, median_minutes: int|null, within_sla: int}>
     */
    public function moderators(ReportRange $range): array
    {
        /** @var list<array{name: string, decided: int, approved: int, rejected: int, changes: int, median_minutes: int|null, within_sla: int}> */
        return $this->cached('moderators', $range, function () use ($range): array {
            $sla = Settings::int(SettingKey::ModerationSlaHours) * 60;
            $decided = fn () => DB::table('moderation_items')
                ->whereNotNull('moderation_items.decided_by_admin_id')
                ->whereNotNull('moderation_items.decided_at')
                ->where('moderation_items.decided_at', '>=', $range->utcFrom())->where('moderation_items.decided_at', '<', $range->utcTo());
            $minutes = 'TIMESTAMPDIFF(MINUTE, moderation_items.submitted_at, moderation_items.decided_at)';

            $medians = SqlMedian::byGroup($decided()->selectRaw("moderation_items.decided_by_admin_id as g, {$minutes} as v"));

            $rows = $decided()
                ->join('admin_users', 'admin_users.id', '=', 'moderation_items.decided_by_admin_id')
                ->groupBy('moderation_items.decided_by_admin_id', 'admin_users.name')
                ->selectRaw('moderation_items.decided_by_admin_id as admin, admin_users.name as name, COUNT(*) as decided,
                    SUM(CASE WHEN moderation_items.status = ? THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN moderation_items.status = ? THEN 1 ELSE 0 END) as rejected,
                    SUM(CASE WHEN moderation_items.status = ? THEN 1 ELSE 0 END) as changes,
                    SUM(CASE WHEN '.$minutes.' <= ? THEN 1 ELSE 0 END) as within_sla',
                    [ModerationStatus::Approved->value, ModerationStatus::Rejected->value, ModerationStatus::ChangesRequested->value, $sla])
                ->orderByDesc('decided')->orderBy('admin_users.name')
                ->get();

            return $rows->map(fn (object $r): array => [
                'name' => (string) $r->name,
                'decided' => (int) $r->decided,
                'approved' => (int) $r->approved,
                'rejected' => (int) $r->rejected,
                'changes' => (int) $r->changes,
                'median_minutes' => $medians[(string) $r->admin] ?? null,
                'within_sla' => (int) $r->within_sla,
            ])->values()->all();
        });
    }

    /** @param Closure(): mixed $compute */
    private function cached(string $kind, ReportRange $range, Closure $compute): mixed
    {
        return Cache::remember('reports:ops:'.$kind.':'.$range->from->toDateString().':'.$range->to->toDateString(), self::CACHE_SECONDS, $compute);
    }
}
