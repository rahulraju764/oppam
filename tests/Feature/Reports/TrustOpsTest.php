<?php

declare(strict_types=1);

use App\Actions\Reports\TakeReportSnapshot;
use App\Domain\Reports\ReportRange;
use App\Domain\Reports\Reports\TrustReport;
use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Enums\ReportMetric;
use App\Models\AbuseCase;
use App\Models\ModerationItem;
use App\Models\ReportSnapshot;
use App\Models\SupportTicket;
use App\Models\VerificationRequest;
use App\Queries\Reports\LiveOps;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/*
| P9.1b — A09 Trust & ops: decisions within their SLA (verification, moderation by type / outcome,
| tickets' first answer), abuse cases past SLA, typical times, and moderator quality — per-admin
| rows (names) ONLY for `reports.staff` (owner 2026-11-13), team totals for everyone else.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00', 'Asia/Kolkata'));
});

function opsAt(string $ist): CarbonImmutable
{
    return CarbonImmutable::parse($ist, 'Asia/Kolkata')->utc();
}

function opsStored(ReportMetric $metric, string $day, string $dimension = ''): ?int
{
    $v = ReportSnapshot::query()->where('metric', $metric->value)->where('day', $day)->where('dimension', $dimension)->value('value');

    return $v === null ? null : (int) $v;
}

it('A09 SLA: moderation decided within moderation.sla_hours (24) counts, a minute over does not; by type and outcome', function (): void {
    $submitted = opsAt('2026-11-18 10:00');
    ModerationItem::factory()->create(['type' => ModerationItemType::Photo, 'status' => ModerationStatus::Approved, 'submitted_at' => $submitted, 'decided_at' => opsAt('2026-11-19 10:00')]);   // exactly 24 h
    ModerationItem::factory()->create(['type' => ModerationItemType::Photo, 'status' => ModerationStatus::Rejected, 'submitted_at' => $submitted, 'decided_at' => opsAt('2026-11-19 10:01')]);   // 24 h 1 min
    VerificationRequest::factory()->create(['submitted_at' => opsAt('2026-11-17 10:00'), 'decided_at' => opsAt('2026-11-19 09:00')]);   // 47 h of 72

    app(TakeReportSnapshot::class)->handle(CarbonImmutable::parse('2026-11-19', 'Asia/Kolkata'));

    expect(opsStored(ReportMetric::ModerationDecided, '2026-11-19'))->toBe(2)
        ->and(opsStored(ReportMetric::ModerationWithinSla, '2026-11-19'))->toBe(1)
        ->and(opsStored(ReportMetric::ModerationDecided, '2026-11-19', 'type:PHOTO'))->toBe(2)
        ->and(opsStored(ReportMetric::ModerationDecided, '2026-11-19', 'outcome:REJECTED'))->toBe(1)
        ->and(opsStored(ReportMetric::VerificationDecided, '2026-11-19'))->toBe(1)
        ->and(opsStored(ReportMetric::VerificationWithinSla, '2026-11-19'))->toBe(1);
});

it('A09 tickets and abuse cases: first answers within their due time, resolutions, cases decided after sla_due_at', function (): void {
    SupportTicket::factory()->create(['created_at' => opsAt('2026-11-19 09:00'), 'first_response_due_at' => opsAt('2026-11-19 13:00'), 'first_responded_at' => opsAt('2026-11-19 12:59'), 'resolved_at' => opsAt('2026-11-19 18:00')]);
    SupportTicket::factory()->create(['created_at' => opsAt('2026-11-19 09:00'), 'first_response_due_at' => opsAt('2026-11-19 10:00'), 'first_responded_at' => opsAt('2026-11-19 11:00')]);
    AbuseCase::factory()->create(['sla_due_at' => opsAt('2026-11-19 08:00'), 'decided_at' => opsAt('2026-11-19 09:00')]);
    AbuseCase::factory()->create(['sla_due_at' => opsAt('2026-11-19 12:00'), 'decided_at' => opsAt('2026-11-19 09:00')]);

    app(TakeReportSnapshot::class)->handle(CarbonImmutable::parse('2026-11-19', 'Asia/Kolkata'));

    expect(opsStored(ReportMetric::TicketsOpened, '2026-11-19'))->toBe(2)
        ->and(opsStored(ReportMetric::TicketsFirstResponded, '2026-11-19'))->toBe(2)
        ->and(opsStored(ReportMetric::TicketsFirstResponseInSla, '2026-11-19'))->toBe(1)
        ->and(opsStored(ReportMetric::TicketsResolved, '2026-11-19'))->toBe(1)
        ->and(opsStored(ReportMetric::CasesDecided, '2026-11-19'))->toBe(2)
        ->and(opsStored(ReportMetric::CasesBreached, '2026-11-19'))->toBe(1);
});

it('A09 typical times: medians over the period', function (): void {
    foreach ([10, 30, 90] as $minutes) {
        ModerationItem::factory()->create(['status' => ModerationStatus::Approved, 'submitted_at' => opsAt('2026-11-15 10:00'), 'decided_at' => opsAt('2026-11-15 10:00')->addMinutes($minutes)]);
    }

    expect(app(LiveOps::class)->medians(ReportRange::of('2026-11-01', '2026-11-19'))['moderation_minutes'])->toBe(30);
});

it('A09 moderator quality: per-admin rows (name, never email) only with reports.staff; others see the team total', function (): void {
    $anu = adminWithRole('moderator');
    $anu->forceFill(['name' => 'Anu Moderator'])->save();
    foreach ([[ModerationStatus::Approved, 20], [ModerationStatus::Rejected, 2000]] as [$status, $minutes]) {
        ModerationItem::factory()->create(['status' => $status, 'decided_by_admin_id' => $anu->id,
            'submitted_at' => opsAt('2026-11-15 10:00'), 'decided_at' => opsAt('2026-11-15 10:00')->addMinutes($minutes)]);
    }
    $range = ReportRange::of('2026-11-01', '2026-11-19');
    $report = app(TrustReport::class);
    $table = fn ($viewer) => collect($report->build($range, false, $viewer)->tables)->firstWhere('key', 'moderator_quality');

    $staff = $table(adminWithRole('super_admin'));   // super admin holds every key, reports.staff included
    expect($staff->rows[0][0])->toBe('Anu Moderator')
        ->and($staff->rows[0][1])->toBe(2)
        ->and($staff->rows[0][6])->toBe('50.0%')
        ->and(json_encode($staff->rows))->not->toContain($anu->email);

    $finance = $table(adminWithRole('finance'));      // reports.view + export, no reports.staff
    expect($finance->title)->toBe('Moderation team')
        ->and(json_encode($finance->rows))->not->toContain('Anu Moderator')
        ->and($finance->rows[0][1])->toBe(2);

    $exported = collect($report->exportTables($range, adminWithRole('finance')))->firstWhere('key', 'moderator_quality');
    expect(json_encode($exported->rows))->not->toContain('Anu Moderator');
});
