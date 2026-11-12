<?php

declare(strict_types=1);

use App\Actions\Reports\TakeReportSnapshot;
use App\Domain\Reports\ReportRange;
use App\Enums\Gender;
use App\Enums\InterestStatus;
use App\Enums\ReportMetric;
use App\Enums\SignupSource;
use App\Models\Interest;
use App\Models\InterestEvent;
use App\Models\Like;
use App\Models\ReportSnapshot;
use App\Queries\Reports\ReportSeries;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| P9.1a — A09 nightly snapshot (PRD A09 "snapshot-backed"): exact numbers per IST day, IST day
| boundaries, idempotent reruns, flows backfillable / levels not, ratios and comparisons from
| stored parts, the 00:30 IST schedule and the command's guard rails.
*/

beforeEach(function (): void {
    seedMasters();
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00', 'Asia/Kolkata'));
});

function snap(string $day): void
{
    app(TakeReportSnapshot::class)->handle(CarbonImmutable::parse($day, 'Asia/Kolkata'));
}

function stored(ReportMetric $metric, string $day, string $dimension = ''): ?int
{
    $v = ReportSnapshot::query()->where('metric', $metric->value)->where('day', $day)->where('dimension', $dimension)->value('value');

    return $v === null ? null : (int) $v;
}

it('A09 growth: signups per IST day by source and gender — 00:00 IST belongs to the new day', function (): void {
    // Stored in UTC, as the app writes timestamps (Eloquent keeps a Carbon's wall-clock time as is).
    $at = fn (string $ist) => CarbonImmutable::parse($ist, 'Asia/Kolkata')->utc();
    campaignMember(['created_at' => $at('2026-11-18 23:59'), 'signup_source' => SignupSource::Utm]);
    campaignMember(['created_at' => $at('2026-11-19 00:00'), 'signup_source' => SignupSource::Broker], female: false);
    campaignMember(['created_at' => $at('2026-11-19 22:30')]);

    snap('2026-11-19');

    expect(stored(ReportMetric::Signups, '2026-11-19'))->toBe(2)
        ->and(stored(ReportMetric::Signups, '2026-11-19', 'source:BROKER'))->toBe(1)
        ->and(stored(ReportMetric::Signups, '2026-11-19', 'source:ORGANIC'))->toBe(1)
        ->and(stored(ReportMetric::Signups, '2026-11-19', 'source:UTM'))->toBeNull()
        ->and(stored(ReportMetric::Signups, '2026-11-19', 'gender:'.Gender::Male->value))->toBe(1);
});

it('A09 engagement: likes, mutual likes, interests by sender gender and tier (Free when no plan), interests after a like', function (): void {
    $day = CarbonImmutable::parse('2026-11-19 12:00', 'Asia/Kolkata')->utc();
    $bride = campaignMember();
    $groom = campaignMember(female: false);
    Like::factory()->create(['from_profile_id' => $groom->profile->id, 'to_profile_id' => $bride->profile->id, 'created_at' => $day->subHour()]);
    Like::factory()->create(['from_profile_id' => $bride->profile->id, 'to_profile_id' => $groom->profile->id, 'created_at' => $day]);   // makes it mutual
    $interest = Interest::factory()->between($groom->profile, $bride->profile)->create(['sent_at' => $day]);
    InterestEvent::query()->insert(['id' => (string) Str::ulid(), 'interest_id' => $interest->id, 'status' => InterestStatus::Pending->value, 'actor_profile_id' => $groom->profile->id, 'created_at' => $day]);
    DB::table('member_activity_days')->insert([['user_id' => $bride->id, 'day' => '2026-11-19'], ['user_id' => $groom->id, 'day' => '2026-11-19']]);

    snap('2026-11-19');

    expect(stored(ReportMetric::LikesSent, '2026-11-19'))->toBe(2)
        ->and(stored(ReportMetric::LikesMutual, '2026-11-19'))->toBe(1)
        ->and(stored(ReportMetric::InterestsSent, '2026-11-19'))->toBe(1)
        ->and(stored(ReportMetric::InterestsSent, '2026-11-19', 'gender:'.Gender::Male->value))->toBe(1)
        ->and(stored(ReportMetric::InterestsSent, '2026-11-19', 'tier:FREE'))->toBe(1)
        ->and(stored(ReportMetric::InterestsAfterLike, '2026-11-19'))->toBe(1)
        ->and(stored(ReportMetric::ActiveMembers, '2026-11-19'))->toBe(2);
});

it('A09 search usage: searches, zero-result searches and filter keys from the anonymous log', function (): void {
    DB::table('search_logs')->insert([
        ['day' => '2026-11-19', 'filters' => '["religion","age_min"]', 'result_count' => 12, 'sort' => null, 'created_at' => now()],
        ['day' => '2026-11-19', 'filters' => '["religion"]', 'result_count' => 0, 'sort' => null, 'created_at' => now()],
        ['day' => '2026-11-19', 'filters' => '["<bad key>"]', 'result_count' => 3, 'sort' => null, 'created_at' => now()],
    ]);

    snap('2026-11-19');

    expect(stored(ReportMetric::Searches, '2026-11-19'))->toBe(3)
        ->and(stored(ReportMetric::SearchesZeroResults, '2026-11-19'))->toBe(1)
        ->and(stored(ReportMetric::SearchFilterUsed, '2026-11-19', 'filter:religion'))->toBe(2)
        ->and(ReportSnapshot::query()->where('dimension', 'like', 'filter:<%')->exists())->toBeFalse();
});

it('A09 levels (marketplace) are stored only for today / yesterday; a rerun replaces the day (idempotent)', function (): void {
    campaignMember();
    campaignMember(female: false);

    snap('2026-11-10');   // a past day: flows only
    expect(stored(ReportMetric::ActiveProfiles, '2026-11-10'))->toBeNull();

    snap('2026-11-19');
    snap('2026-11-19');
    expect(stored(ReportMetric::ActiveProfiles, '2026-11-19'))->toBe(2)
        ->and(stored(ReportMetric::ActiveProfiles, '2026-11-19', 'gender:'.Gender::Female->value))->toBe(1)
        ->and(ReportSnapshot::query()->where('day', '2026-11-19')->where('metric', 'active_profiles')->where('dimension', '')->count())->toBe(1);
});

it('A09 backfill (review fix, Major): captured metrics are never recomputed for days before their table still holds data — stored history is not zeroed', function (): void {
    ReportSnapshot::query()->insert([
        ['metric' => 'searches', 'day' => '2026-08-01', 'dimension' => '', 'value' => 42, 'computed_at' => now()],
        ['metric' => 'search_filter_used', 'day' => '2026-08-01', 'dimension' => 'filter:religion', 'value' => 9, 'computed_at' => now()],
        ['metric' => 'active_members', 'day' => '2026-08-01', 'dimension' => '', 'value' => 300, 'computed_at' => now()],
    ]);
    // The capture tables now only hold recent days (older rows pruned).
    DB::table('search_logs')->insert(['day' => '2026-11-18', 'filters' => '[]', 'result_count' => 1, 'sort' => null, 'created_at' => now()]);
    DB::table('member_activity_days')->insert(['user_id' => campaignMember()->id, 'day' => '2026-11-18']);

    snap('2026-08-01');

    expect(stored(ReportMetric::Searches, '2026-08-01'))->toBe(42)
        ->and(stored(ReportMetric::SearchFilterUsed, '2026-08-01', 'filter:religion'))->toBe(9)
        ->and(stored(ReportMetric::ActiveMembers, '2026-08-01'))->toBe(300)
        ->and(stored(ReportMetric::Signups, '2026-08-01'))->toBe(0);   // flows from timestamps are still backfilled

    snap('2026-11-18');   // inside the capture: recomputed
    expect(stored(ReportMetric::Searches, '2026-11-18'))->toBe(1)->and(stored(ReportMetric::ActiveMembers, '2026-11-18'))->toBe(1);
});

it('A09 mutual likes (review fix): two likes in the same second count as ONE mutual pair', function (): void {
    $at = CarbonImmutable::parse('2026-11-19 12:00', 'Asia/Kolkata')->utc();
    $a = campaignMember();
    $b = campaignMember(female: false);
    Like::factory()->create(['from_profile_id' => $a->profile->id, 'to_profile_id' => $b->profile->id, 'created_at' => $at]);
    Like::factory()->create(['from_profile_id' => $b->profile->id, 'to_profile_id' => $a->profile->id, 'created_at' => $at]);

    snap('2026-11-19');

    expect(stored(ReportMetric::LikesMutual, '2026-11-19'))->toBe(1);
});

it('A09 read side: flows sum over the range, the previous period is the same length right before, ratios come from parts', function (): void {
    foreach (['2026-11-01' => [10, 2], '2026-11-02' => [30, 3], '2026-10-31' => [5, 1]] as $day => [$likes, $mutual]) {
        ReportSnapshot::query()->insert([
            ['metric' => 'likes_sent', 'day' => $day, 'dimension' => '', 'value' => $likes, 'computed_at' => now()],
            ['metric' => 'likes_mutual', 'day' => $day, 'dimension' => '', 'value' => $mutual, 'computed_at' => now()],
        ]);
    }
    $range = ReportRange::of('2026-11-01', '2026-11-02');
    $series = app(ReportSeries::class);

    expect($series->totals($range, [ReportMetric::LikesSent, ReportMetric::LikesMutual]))->toBe(['likes_sent' => 40, 'likes_mutual' => 5])
        ->and($range->previous()->from->toDateString())->toBe('2026-10-30')
        ->and($range->previous()->to->toDateString())->toBe('2026-10-31')
        ->and($series->totals($range->previous(), [ReportMetric::LikesSent])['likes_sent'])->toBe(5)
        ->and($series->daily($range, [ReportMetric::LikesSent])['series']['likes_sent'])->toBe([10, 30]);
    // 5 / 40 = 12.5 % — never an average of the daily 20 % and 10 %.
    expect(App\Data\Reports\ReportStatData::delta(12.5, 20.0, true))->toBe('-7.5 pts');
});

it('A09 command: yesterday by default; a backfill range; refuses future, reversed or too-long ranges', function (): void {
    $this->artisan('reports:snapshot')->assertSuccessful();
    expect(ReportSnapshot::query()->where('day', '2026-11-19')->exists())->toBeTrue();

    $this->artisan('reports:snapshot', ['--from' => '2026-11-01', '--to' => '2026-11-03'])->assertSuccessful();
    expect(ReportSnapshot::query()->where('day', '2026-11-02')->where('metric', 'signups')->exists())->toBeTrue();

    $this->artisan('reports:snapshot', ['--day' => '2026-12-01'])->assertFailed();
    $this->artisan('reports:snapshot', ['--from' => '2026-11-05', '--to' => '2026-11-01'])->assertFailed();
    $this->artisan('reports:snapshot', ['--from' => '2025-01-01', '--to' => '2026-11-01'])->assertFailed();
    $this->artisan('reports:snapshot', ['--day' => 'yesterday-ish'])->assertFailed();
});

it('A09 schedules: the snapshot at 00:30 IST, capture pruning at 04:30 IST, export expiry hourly', function (): void {
    $events = collect(app(Schedule::class)->events());
    $snapshot = $events->first(fn ($e) => str_contains((string) $e->command, 'reports:snapshot'));
    $prune = $events->first(fn ($e) => str_contains((string) $e->description, 'PruneReportCapture'));

    expect($snapshot->expression)->toBe('30 0 * * *')->and($snapshot->timezone)->toBe('Asia/Kolkata')
        ->and($prune->expression)->toBe('30 4 * * *')
        ->and($events->contains(fn ($e) => str_contains((string) $e->description, 'ExpireAdminExports')))->toBeTrue();
});
