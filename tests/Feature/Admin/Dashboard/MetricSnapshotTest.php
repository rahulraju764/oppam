<?php

declare(strict_types=1);

use App\Actions\Admin\Metrics\TakeMetricSnapshot;
use App\Enums\Gender;
use App\Enums\InterestStatus;
use App\Enums\MessageType;
use App\Enums\Metric;
use App\Enums\PlanCode;
use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Interest;
use App\Models\InterestEvent;
use App\Models\Like;
use App\Models\Message;
use App\Models\MetricSnapshot;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Queries\Admin\DashboardMetrics;
use Carbon\CarbonImmutable;
use Database\Seeders\PlansSeeder;

/*
| P3.5 — KPI snapshots (PRD A02: "KPIs from nightly/hourly snapshot tables (`metric_snapshots`),
| never aggregated live; 'as of' timestamp shown"): what each metric counts, the IST day boundary,
| idempotent re-runs, closing yesterday, the command and its schedule, and what the dashboard
| reads back (30-day change, series, as of).
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-11 15:00', 'Asia/Kolkata'));
});

function snapValue(Metric $metric, string $day = '2026-10-11'): ?int
{
    return MetricSnapshot::query()->where('metric', $metric->value)->whereDate('day', $day)->value('value');
}

it('A02: one row per metric for the IST day, counting what each definition says', function (): void {
    $groom = groom();                                    // active male, registered today
    $bride = bride();                                    // active female, registered today
    $groom->forceFill(['last_seen_at' => now()->subDays(2)])->save();
    $bride->forceFill(['last_seen_at' => now()->subDays(20)])->save();
    $old = memberWithPhone('+919844400001');
    $old->forceFill(['created_at' => now()->subDays(40)])->save();
    $old->profile->forceFill(['status' => ProfileStatus::Suspended, 'published_at' => now()->subDays(39)])->save();
    $deleted = memberWithPhone('+919844400002');
    $deleted->forceFill(['status' => UserStatus::Deleted])->save();
    $deleted->profile->forceFill(['status' => ProfileStatus::Deleted, 'published_at' => now()->subDays(9)])->save();
    User::factory()->create(['role' => UserRole::Broker]);   // brokers are not members
    $groom->profile->forceFill(['published_at' => now()->subHour()])->save();
    $bride->profile->forceFill(['published_at' => now()->subDays(3)])->save();

    Subscription::factory()->create(['profile_id' => $groom->profile->id, 'plan_id' => Plan::query()->where('code', PlanCode::Gold->value)->sole()->id]);
    Like::factory()->between($groom->profile, $bride->profile)->create();
    Like::factory()->between($bride->profile, $groom->profile)->create(['created_at' => now()->subDays(2)]);
    $interest = Interest::factory()->between($groom->profile, $bride->profile)->accepted()->create();
    InterestEvent::record($interest, InterestStatus::Pending, $groom->profile->id);
    InterestEvent::record($interest, InterestStatus::Accepted, $bride->profile->id);
    $conversation = App\Models\Conversation::factory()->create();
    foreach ([MessageType::System, MessageType::Text] as $type) {
        $message = new Message;
        $message->forceFill(['conversation_id' => $conversation->id, 'client_id' => (string) Illuminate\Support\Str::uuid(), 'type' => $type, 'body' => 'x'])->save();
    }

    $stored = app(TakeMetricSnapshot::class)->handle();

    expect($stored)->toHaveCount(count(Metric::cases()))
        ->and(snapValue(Metric::TotalMembers))->toBe(3)          // groom, bride, old — not the deleted one, not the broker
        ->and(snapValue(Metric::NewMembers))->toBe(2)            // registered today (the deleted account never counts)
        ->and(snapValue(Metric::ActiveMembers7d))->toBe(1)
        ->and(snapValue(Metric::PaidMembers))->toBe(1)
        ->and(snapValue(Metric::Activations))->toBe(1)
        ->and(snapValue(Metric::ActiveMale))->toBe(1)
        ->and(snapValue(Metric::ActiveFemale))->toBe(1)
        ->and(snapValue(Metric::Likes))->toBe(1)
        ->and(snapValue(Metric::InterestsSent))->toBe(1)
        ->and(snapValue(Metric::InterestsAccepted))->toBe(1)
        ->and(snapValue(Metric::Messages))->toBe(1);             // the SYSTEM message is not counted
});

it('the day is the IST day: 23:59 and 00:01 IST fall on different days', function (): void {
    $me = groom();
    $other = bride();
    Like::factory()->between($me->profile, $other->profile)->create(['created_at' => CarbonImmutable::parse('2026-10-10 23:59', 'Asia/Kolkata')->utc()]);
    Like::factory()->between($other->profile, $me->profile)->create(['created_at' => CarbonImmutable::parse('2026-10-11 00:01', 'Asia/Kolkata')->utc()]);

    app(TakeMetricSnapshot::class)->handle();
    app(TakeMetricSnapshot::class)->handle(CarbonImmutable::parse('2026-10-10', 'Asia/Kolkata'));

    expect(snapValue(Metric::Likes))->toBe(1)->and(snapValue(Metric::Likes, '2026-10-10'))->toBe(1);
});

it('re-running rewrites the day\'s row (no duplicates) and moves "as of"; closing yesterday writes flows only', function (): void {
    $me = groom();
    app(TakeMetricSnapshot::class)->handle();
    $first = MetricSnapshot::query()->where('metric', Metric::Likes->value)->sole();

    $this->travel(1)->hours();
    Like::factory()->between($me->profile, bride()->profile)->create();
    app(TakeMetricSnapshot::class)->handle();

    $again = MetricSnapshot::query()->where('metric', Metric::Likes->value)->sole();
    expect($again->value)->toBe(1)->and($again->id)->toBe($first->id)
        ->and($again->computed_at->gt($first->computed_at))->toBeTrue()
        ->and(MetricSnapshot::query()->count())->toBe(count(Metric::cases()));

    $yesterday = app(TakeMetricSnapshot::class)->handle(CarbonImmutable::parse('2026-10-10', 'Asia/Kolkata'));
    expect(array_keys($yesterday))->not->toContain(Metric::TotalMembers->value, Metric::PaidMembers->value)   // levels are "as of now": never back-dated
        ->toContain(Metric::NewMembers->value, Metric::Likes->value);
});

it('metrics:snapshot stores today, --yesterday closes the previous IST day; both are scheduled', function (): void {
    groom();

    $this->artisan('metrics:snapshot')->expectsOutputToContain('for 2026-10-11')->assertSuccessful();
    $this->artisan('metrics:snapshot --yesterday')->expectsOutputToContain('for 2026-10-10')->assertSuccessful();

    expect(snapValue(Metric::NewMembers))->toBe(1)->and(snapValue(Metric::NewMembers, '2026-10-10'))->toBe(0);

    $events = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())->filter(fn ($e): bool => str_contains((string) $e->command, 'metrics:snapshot'));
    $hourly = $events->first(fn ($e): bool => ! str_contains((string) $e->command, '--yesterday'));
    $nightly = $events->first(fn ($e): bool => str_contains((string) $e->command, '--yesterday'));

    expect($hourly?->expression)->toBe('5 * * * *')
        ->and($nightly?->expression)->toBe('10 0 * * *')->and($nightly?->timezone)->toBe('Asia/Kolkata');
});

it('the dashboard reads snapshots only: latest value, change against 30 days earlier, a 30-day series and "as of"', function (): void {
    $put = function (Metric $metric, string $day, int $value, string $at = '2026-10-11 14:05'): void {
        $row = new MetricSnapshot;
        $row->forceFill(['metric' => $metric, 'day' => $day, 'value' => $value, 'computed_at' => CarbonImmutable::parse($at, 'Asia/Kolkata')->utc()])->save();
    };
    $put(Metric::TotalMembers, '2026-10-11', 1200);
    $put(Metric::TotalMembers, '2026-09-11', 1000, '2026-09-11 23:05');
    $put(Metric::NewMembers, '2026-10-11', 12);
    $put(Metric::NewMembers, '2026-10-09', 30, '2026-10-10 00:10');
    $put(Metric::Likes, '2026-10-10', 7, '2026-10-11 00:10');      // nothing yet today: the latest day is shown
    $put(Metric::ActiveMale, '2026-10-11', 55);
    User::factory()->count(3)->create();                             // live rows never change what the dashboard shows

    $metrics = app(DashboardMetrics::class);
    $kpis = collect($metrics->kpis())->keyBy(fn (array $kpi): string => $kpi['metric']->value);
    $series = $metrics->series(Metric::NewMembers);

    expect($kpis[Metric::TotalMembers->value])->toMatchArray(['value' => 1200, 'delta' => 20, 'deltaLabel' => '+20%'])
        ->and($kpis[Metric::NewMembers->value])->toMatchArray(['value' => 12, 'delta' => null, 'deltaLabel' => null])
        ->and($kpis[Metric::Likes->value]['value'])->toBe(7)
        ->and($kpis[Metric::PaidMembers->value]['value'])->toBeNull()
        ->and($series['labels'])->toHaveCount(30)->and(end($series['labels']))->toBe('11 Oct')
        ->and(array_slice($series['values'], -3))->toBe([30, 0, 12])
        ->and($metrics->latest(Metric::ActiveMale))->toBe(55)->and($metrics->latest(Metric::ActiveFemale))->toBeNull()
        ->and($metrics->asOf()?->setTimezone('Asia/Kolkata')->format('Y-m-d H:i'))->toBe('2026-10-11 14:05');
});

it('a daily count compares FINISHED days (yesterday against 30 days before it) — a half-day today never reads as a fall', function (): void {
    $put = function (Metric $metric, string $day, int $value): void {
        $row = new MetricSnapshot;
        $row->forceFill(['metric' => $metric, 'day' => $day, 'value' => $value, 'computed_at' => now()])->save();
    };
    $put(Metric::Likes, '2026-10-11', 3);       // today so far
    $put(Metric::Likes, '2026-10-10', 80);      // yesterday, closed
    $put(Metric::Likes, '2026-09-10', 100);     // 30 days before yesterday
    $put(Metric::Likes, '2026-09-11', 1000);    // 30 days before today: not the comparison for a daily count
    $put(Metric::Messages, '2026-10-10', 9);
    $put(Metric::Messages, '2026-09-10', 0);    // nothing to compare against
    $put(Metric::TotalMembers, '2026-10-11', 90);
    $put(Metric::TotalMembers, '2026-09-11', 100);

    $kpis = collect(app(DashboardMetrics::class)->kpis())->keyBy(fn (array $kpi): string => $kpi['metric']->value);

    expect($kpis[Metric::Likes->value])->toMatchArray(['value' => 3, 'deltaLabel' => '-20%'])
        ->and($kpis[Metric::Messages->value]['deltaLabel'])->toBeNull()
        ->and($kpis[Metric::TotalMembers->value]['deltaLabel'])->toBe('-10%');   // a level: today against 30 days earlier
});

it('a snapshot row with a metric key this code no longer knows is ignored, not an error', function (): void {
    Illuminate\Support\Facades\DB::table('metric_snapshots')->insert(['id' => (string) Illuminate\Support\Str::ulid(), 'metric' => 'renamed_long_ago',
        'day' => '2026-10-11', 'value' => 5, 'computed_at' => now()]);
    $row = new MetricSnapshot;
    $row->forceFill(['metric' => Metric::Likes, 'day' => '2026-10-11', 'value' => 4, 'computed_at' => now()])->save();

    $kpis = collect(app(DashboardMetrics::class)->kpis())->keyBy(fn (array $kpi): string => $kpi['metric']->value);

    expect($kpis[Metric::Likes->value]['value'])->toBe(4)->and(app(DashboardMetrics::class)->asOf())->not->toBeNull();
});

it('the hourly snapshot is not locked out for a day by a run that was killed (10-minute overlap lock)', function (): void {
    $events = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())->filter(fn ($e): bool => str_contains((string) $e->command, 'metrics:snapshot'));

    expect($events)->toHaveCount(2)->and($events->every(fn ($e): bool => $e->withoutOverlapping && $e->expiresAt === 10))->toBeTrue();
});

it('every metric has a label and a definition for its tooltip', function (): void {
    foreach (Metric::cases() as $metric) {
        expect($metric->label())->not->toBe('')->and($metric->definition())->not->toBe('');
    }

    expect(Gender::Male->value)->toBe('MALE');   // the snapshot's gender split uses the enum values
});
