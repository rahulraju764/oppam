<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Data\Reports\ReportStatData;
use App\Enums\ReportMetric;
use App\Queries\Reports\LiveMetrics;
use App\Queries\Reports\ReportSeries;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;

/**
 * The A09 weekly KPI digest's numbers (P9.1b): last Monday–Sunday (IST) against the week before,
 * from the same stored snapshots and live queries as the reports — so the email and the screens
 * never disagree. Churn is only final 30 days on, so it is the week that ended five weeks ago.
 * Numbers only: no member or admin names, no contact details.
 */
final class KpiDigest
{
    public function __construct(
        private readonly ReportSeries $series,
        private readonly LiveMetrics $live,
    ) {}

    public function lastWeek(?CarbonImmutable $now = null): ReportRange
    {
        $monday = ($now ?? CarbonImmutable::now())->setTimezone((string) config('oppam.display_timezone'))->startOfWeek(CarbonImmutable::MONDAY);

        return new ReportRange($monday->subWeek(), $monday->subDay());
    }

    /** @return list<array{label: string, value: string, delta: string|null, report: string}> */
    public function lines(ReportRange $week): array
    {
        $flows = [ReportMetric::Signups, ReportMetric::ProfilesApproved, ReportMetric::ActiveMembers, ReportMetric::InterestsSent,
            ReportMetric::InterestsAccepted, ReportMetric::RevenuePaidPaise, ReportMetric::RevenueRefundedPaise, ReportMetric::FirstPaidMembers,
            ReportMetric::ModerationDecided, ReportMetric::ModerationWithinSla];
        $now = $this->series->totals($week, $flows);
        $before = $this->series->totals($week->previous(), $flows);
        $chatNow = $this->live->conversations($week);
        $chatBefore = $this->live->conversations($week->previous());
        $churnWeek = new ReportRange($week->from->subDays(ReportMetric::CHURN_DAYS + 7), $week->to->subDays(ReportMetric::CHURN_DAYS + 7));
        $churn = $this->series->totals($churnWeek, [ReportMetric::PaidPlansEnded, ReportMetric::PaidPlansChurned]);

        $pct = fn (int $part, int $whole): ?float => $whole > 0 ? round($part / $whole * 100, 1) : null;
        $p = fn (?float $v): string => $v === null ? '—' : number_format($v, 1).'%';
        $net = fn (array $x): int => $x['revenue_paid_paise'] - $x['revenue_refunded_paise'];

        return [
            ['label' => __('Signups'), 'value' => number_format($now['signups']), 'delta' => ReportStatData::delta($now['signups'], $before['signups']), 'report' => 'growth'],
            ['label' => __('Profiles approved'), 'value' => number_format($now['profiles_approved']), 'delta' => ReportStatData::delta($now['profiles_approved'], $before['profiles_approved']), 'report' => 'growth'],
            ['label' => __('Average daily active members'), 'value' => number_format($now['active_members'] / 7, 1), 'delta' => ReportStatData::delta($now['active_members'], $before['active_members']), 'report' => 'engagement'],
            ['label' => __('Interests sent'), 'value' => number_format($now['interests_sent']), 'delta' => ReportStatData::delta($now['interests_sent'], $before['interests_sent']), 'report' => 'engagement'],
            ['label' => __('Acceptance rate'), 'value' => $p($pct($now['interests_accepted'], $now['interests_sent'])),
                'delta' => ReportStatData::delta($pct($now['interests_accepted'], $now['interests_sent']), $pct($before['interests_accepted'], $before['interests_sent']), true), 'report' => 'engagement'],
            ['label' => __('Conversations with a reply'), 'value' => $p($pct($chatNow['replied'], $chatNow['started'])),
                'delta' => ReportStatData::delta($pct($chatNow['replied'], $chatNow['started']), $pct($chatBefore['replied'], $chatBefore['started']), true), 'report' => 'engagement'],
            ['label' => __('Net revenue'), 'value' => Money::paise(max(0, $net($now)))->formatWithSymbol(), 'delta' => ReportStatData::delta($net($now), $net($before)), 'report' => 'revenue'],
            ['label' => __('New paying members'), 'value' => number_format($now['first_paid_members']), 'delta' => ReportStatData::delta($now['first_paid_members'], $before['first_paid_members']), 'report' => 'revenue'],
            ['label' => __('Churn (plans that ended :from – :to)', ['from' => $churnWeek->from->format('d M'), 'to' => $churnWeek->to->format('d M')]),
                'value' => $p($pct($churn['paid_plans_churned'], $churn['paid_plans_ended'])), 'delta' => null, 'report' => 'revenue'],
            ['label' => __('Moderation within SLA'), 'value' => $p($pct($now['moderation_within_sla'], $now['moderation_decided'])),
                'delta' => ReportStatData::delta($pct($now['moderation_within_sla'], $now['moderation_decided']), $pct($before['moderation_within_sla'], $before['moderation_decided']), true), 'report' => 'trust'],
        ];
    }
}
