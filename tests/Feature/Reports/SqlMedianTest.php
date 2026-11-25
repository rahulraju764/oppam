<?php

declare(strict_types=1);

use App\Domain\Reports\ReportRange;
use App\Enums\ModerationStatus;
use App\Models\ModerationItem;
use App\Models\SupportTicket;
use App\Models\VerificationRequest;
use App\Queries\Reports\Concerns\SqlMedian;
use App\Queries\Reports\LiveOps;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| P9.3c — A09 "typical" times computed in SQL (one query) instead of loading every value into PHP.
| The rule is the reports' long-standing UPPER median: the value at 1-based position floor(n/2)+1 in
| ascending order (an even count picks the higher middle value); NULLs are left out; nothing → null.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00', 'Asia/Kolkata'));
});

/** A one-column (`v`) subquery holding exactly these values. @param list<int|null> $values */
function medianValues(array $values): Builder
{
    $query = DB::query()->selectRaw('CAST(NULL AS SIGNED) as v')->whereRaw('1 = 0');
    foreach ($values as $value) {
        $query->unionAll(DB::query()->selectRaw('CAST(? AS SIGNED) as v', [$value]));
    }

    return DB::query()->fromSub($query, 'src')->select('v');
}

/** The PHP rule the reports used before P9.3c. @param list<int|null> $values */
function phpUpperMedian(array $values): ?int
{
    $ints = array_values(array_map('intval', array_filter($values, fn ($v): bool => $v !== null)));
    sort($ints);

    return $ints === [] ? null : $ints[intdiv(count($ints), 2)];
}

it('P9.3c median: odd, even (upper middle), single, empty, ties and NULLs', function (array $values, ?int $median): void {
    expect(SqlMedian::of(medianValues($values)))->toBe($median);
})->with([
    'odd' => [[90, 10, 30], 30],
    'even — the upper middle value' => [[10, 20, 30, 40], 30],
    'single' => [[7], 7],
    'empty' => [[], null],
    'ties' => [[5, 5, 5, 9], 5],
    'NULLs are left out' => [[null, 4, null, 8, 12], 8],
    'only NULLs' => [[null, null], null],
    'negative values sort numerically' => [[-3, 10, 2], 2],
]);

it('P9.3c median: SQL and the old PHP rule agree on random data (200 runs)', function (): void {
    mt_srand(9301);
    foreach (range(1, 200) as $_) {
        $values = array_map(fn (): ?int => mt_rand(0, 9) === 0 ? null : mt_rand(0, 500), range(1, mt_rand(0, 25)));
        expect(SqlMedian::of(medianValues($values)))->toBe(phpUpperMedian($values), json_encode($values));
    }
});

it('P9.3c median by group: one upper median per group, groups without values absent', function (): void {
    $query = DB::query()->selectRaw('CAST(NULL AS CHAR) as g, CAST(NULL AS SIGNED) as v')->whereRaw('1 = 0');
    foreach ([['a', 1], ['a', 9], ['a', 5], ['b', 4], ['b', 8], ['c', null]] as [$g, $v]) {
        $query->unionAll(DB::query()->selectRaw('? as g, CAST(? AS SIGNED) as v', [$g, $v]));
    }

    expect(SqlMedian::byGroup(DB::query()->fromSub($query, 'src')->select('g', 'v')))->toBe(['a' => 5, 'b' => 8]);
});

it('A09 typical times (P9.3c): verification hours and ticket first-answer hours, even counts pick the upper middle', function (): void {
    $start = CarbonImmutable::parse('2026-11-15 10:00', 'Asia/Kolkata')->utc();
    foreach ([2, 4, 6, 50] as $hours) {
        VerificationRequest::factory()->create(['submitted_at' => $start, 'decided_at' => $start->addHours($hours)]);
    }
    foreach ([1, 3, 9] as $hours) {
        SupportTicket::factory()->create(['created_at' => $start, 'first_responded_at' => $start->addHours($hours)]);
    }

    $medians = app(LiveOps::class)->medians(ReportRange::of('2026-11-01', '2026-11-19'));

    expect($medians['verification_hours'])->toBe(6)->and($medians['ticket_hours'])->toBe(3);
});

it('A09 moderator quality (P9.3c): per-admin counts, typical minutes and SLA share come from SQL, busiest first', function (): void {
    $anu = adminWithRole('moderator');
    $anu->forceFill(['name' => 'Anu'])->save();
    $ben = adminWithRole('moderator');
    $ben->forceFill(['name' => 'Ben'])->save();
    $start = CarbonImmutable::parse('2026-11-15 10:00', 'Asia/Kolkata')->utc();
    foreach ([[$anu, ModerationStatus::Approved, 10], [$anu, ModerationStatus::Rejected, 40], [$anu, ModerationStatus::ChangesRequested, 30],
        [$anu, ModerationStatus::Approved, 5000], [$ben, ModerationStatus::Approved, 15]] as [$admin, $status, $minutes]) {
        ModerationItem::factory()->create(['status' => $status, 'decided_by_admin_id' => $admin->id,
            'submitted_at' => $start, 'decided_at' => $start->addMinutes($minutes)]);
    }

    $rows = app(LiveOps::class)->moderators(ReportRange::of('2026-11-01', '2026-11-19'));

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toBe(['name' => 'Anu', 'decided' => 4, 'approved' => 2, 'rejected' => 1, 'changes' => 1,
            'median_minutes' => 40, 'within_sla' => 3])   // [10, 30, 40, 5000] → upper middle 40; 5000 min is past a 24 h SLA
        ->and($rows[1])->toMatchArray(['name' => 'Ben', 'decided' => 1, 'median_minutes' => 15, 'within_sla' => 1]);
});

it('P9.3c: the typical times run a fixed number of queries however many decisions there are', function (): void {
    $start = CarbonImmutable::parse('2026-11-15 10:00', 'Asia/Kolkata')->utc();
    $count = function (int $n) use ($start): int {
        ModerationItem::factory()->count($n)->create(['status' => ModerationStatus::Approved, 'submitted_at' => $start, 'decided_at' => $start->addMinutes(30)]);
        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(LiveOps::class)->medians(ReportRange::of('2026-11-01', '2026-11-19'));
        app(LiveOps::class)->moderators(ReportRange::of('2026-11-01', '2026-11-19'));
        $queries = count(collect(DB::getQueryLog())->filter(fn (array $q): bool => ! str_contains($q['query'], 'cache')));
        DB::disableQueryLog();

        return $queries;
    };

    $count(1);   // warm-up: the first call also reads the (then memoised) SLA setting — not a per-row cost

    expect($count(30))->toBe($count(2));
});
