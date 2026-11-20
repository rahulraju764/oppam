<?php

declare(strict_types=1);

namespace App\Queries\Reports;

use App\Domain\Reports\ReportRange;
use App\Enums\ModerationStatus;
use App\Enums\SettingKey;
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
            'moderation_minutes' => $this->median(DB::table('moderation_items')->whereNotNull('decided_at')
                ->where('decided_at', '>=', $range->utcFrom())->where('decided_at', '<', $range->utcTo())
                ->selectRaw('TIMESTAMPDIFF(MINUTE, submitted_at, decided_at) as v')->pluck('v')->all()),
            'verification_hours' => $this->median(DB::table('verification_requests')->whereNotNull('decided_at')
                ->where('decided_at', '>=', $range->utcFrom())->where('decided_at', '<', $range->utcTo())
                ->selectRaw('TIMESTAMPDIFF(HOUR, submitted_at, decided_at) as v')->pluck('v')->all()),
            'ticket_hours' => $this->median(DB::table('support_tickets')->whereNotNull('first_responded_at')
                ->where('first_responded_at', '>=', $range->utcFrom())->where('first_responded_at', '<', $range->utcTo())
                ->selectRaw('TIMESTAMPDIFF(HOUR, created_at, first_responded_at) as v')->pluck('v')->all()),
        ]);
    }

    /**
     * Moderator quality for the period, busiest first.
     *
     * @return list<array{name: string, decided: int, approved: int, rejected: int, changes: int, median_minutes: int|null, within_sla: int}>
     */
    public function moderators(ReportRange $range): array
    {
        /** @var list<array{name: string, decided: int, approved: int, rejected: int, changes: int, median_minutes: int|null, within_sla: int}> */
        return $this->cached('moderators', $range, function () use ($range): array {
            $sla = Settings::int(SettingKey::ModerationSlaHours) * 60;
            $rows = DB::table('moderation_items')
                ->join('admin_users', 'admin_users.id', '=', 'moderation_items.decided_by_admin_id')
                ->whereNotNull('moderation_items.decided_at')
                ->where('moderation_items.decided_at', '>=', $range->utcFrom())->where('moderation_items.decided_at', '<', $range->utcTo())
                ->selectRaw('moderation_items.decided_by_admin_id as admin, admin_users.name as name, moderation_items.status as status, TIMESTAMPDIFF(MINUTE, moderation_items.submitted_at, moderation_items.decided_at) as minutes')
                ->get();

            $out = [];
            foreach ($rows->groupBy('admin') as $group) {
                $minutes = $group->pluck('minutes')->map(fn ($m): int => (int) $m)->all();
                $out[] = [
                    'name' => (string) $group->first()->name,
                    'decided' => $group->count(),
                    'approved' => $group->where('status', ModerationStatus::Approved->value)->count(),
                    'rejected' => $group->where('status', ModerationStatus::Rejected->value)->count(),
                    'changes' => $group->where('status', ModerationStatus::ChangesRequested->value)->count(),
                    'median_minutes' => $this->median($minutes),
                    'within_sla' => count(array_filter($minutes, fn (int $m): bool => $m <= $sla)),
                ];
            }
            usort($out, fn (array $a, array $b): int => $b['decided'] <=> $a['decided']);

            return $out;
        });
    }

    /** @param list<mixed> $values */
    private function median(array $values): ?int
    {
        $ints = array_map('intval', array_filter($values, fn (mixed $v): bool => $v !== null));
        sort($ints);

        return $ints === [] ? null : $ints[intdiv(count($ints), 2)];
    }

    /** @param Closure(): mixed $compute */
    private function cached(string $kind, ReportRange $range, Closure $compute): mixed
    {
        return Cache::remember('reports:ops:'.$kind.':'.$range->from->toDateString().':'.$range->to->toDateString(), self::CACHE_SECONDS, $compute);
    }
}
