<?php

declare(strict_types=1);

use App\Livewire\Admin\Reports\Catalogue;
use App\Livewire\Admin\Reports\Exports;
use App\Livewire\Admin\Reports\ReportView;
use App\Models\Profile;
use App\Models\ReportSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P9.1a — A09 report screens: catalogue, each built report renders from snapshots (presets,
| custom range validation, compare deltas, empty states, "tracked since" notes), the marketplace
| work queue, my exports; reports.view required; locked report key; bounded queries.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00', 'Asia/Kolkata'));
    $this->actingAs(adminWithRole('super_admin'), 'admin');
});

function seedSnapshots(string $from, string $to, int $signups): void
{
    for ($d = CarbonImmutable::parse($from); $d->lessThanOrEqualTo(CarbonImmutable::parse($to)); $d = $d->addDay()) {
        foreach (['signups' => $signups, 'profiles_approved' => 1, 'likes_sent' => 10, 'likes_mutual' => 2, 'interests_sent' => 4, 'interests_accepted' => 1, 'active_members' => 7] as $metric => $value) {
            ReportSnapshot::query()->insert(['metric' => $metric, 'day' => $d->toDateString(), 'dimension' => '', 'value' => $value, 'computed_at' => now()]);
        }
        ReportSnapshot::query()->insert(['metric' => 'signups', 'day' => $d->toDateString(), 'dimension' => 'source:ORGANIC', 'value' => $signups, 'computed_at' => now()]);
    }
}

it('A09 catalogue: the five reports with their question, each opens', function (): void {
    Livewire::test(Catalogue::class)->assertOk()
        ->assertSee('Growth')->assertSee('Engagement')->assertSee('Marketplace health')->assertSee('Revenue')->assertSee('Trust & operations')
        ->assertSeeHtml('dusk="report-growth"')->assertSeeHtml('dusk="report-revenue"')->assertSeeHtml('dusk="report-trust"');   // all five built (P9.1b)
});

it('A09 growth report: numbers from the snapshots for the preset, compare shows the change against the period before', function (): void {
    seedSnapshots('2026-11-13', '2026-11-19', 3);   // last 7 days: 21 signups
    seedSnapshots('2026-11-06', '2026-11-12', 2);   // the 7 before: 14

    Livewire::test(ReportView::class, ['report' => 'growth'])
        ->set('preset', '7d')
        ->assertSee('13 Nov 2026 – 19 Nov 2026')->assertSee('21')
        ->assertSee('Signups by source')->assertSee('Activation funnel')->assertSee('Weekly cohorts')
        ->assertSee('tracked since')
        ->set('compare', true)->assertSee('+50%')->assertSee('compared with 06 Nov 2026 – 12 Nov 2026');
});

it('A09 engagement report: ratios from their parts (mutual 20.0 %), unique active members, conversations and notifications sections', function (): void {
    seedSnapshots('2026-11-13', '2026-11-19', 1);
    DB::table('member_activity_days')->insert([['user_id' => campaignMember()->id, 'day' => '2026-11-15']]);

    Livewire::test(ReportView::class, ['report' => 'engagement'])->set('preset', '7d')
        ->assertSee('Mutual-like rate')->assertSee('20.0%')
        ->assertSee('Unique active members')->assertSee('Interests by the sender')->assertSee('Notifications by channel')->assertSee('Most used search filters');
});

it('A09 marketplace report: levels + the "no interest" work queue (live, paginated, linked to the member page)', function (): void {
    $lonely = campaignMember(profile: ['published_at' => now()->subDays(60)]);
    ReportSnapshot::query()->insert([
        ['metric' => 'active_profiles', 'day' => '2026-11-19', 'dimension' => '', 'value' => 10, 'computed_at' => now()],
        ['metric' => 'active_profiles', 'day' => '2026-11-19', 'dimension' => 'gender:FEMALE', 'value' => 6, 'computed_at' => now()],
        ['metric' => 'active_profiles', 'day' => '2026-11-19', 'dimension' => 'gender:MALE', 'value' => 4, 'computed_at' => now()],
        ['metric' => 'active_profiles_with_photo', 'day' => '2026-11-19', 'dimension' => '', 'value' => 5, 'computed_at' => now()],
    ]);

    Livewire::test(ReportView::class, ['report' => 'marketplace'])->set('preset', '7d')
        ->assertSee('Brides per 100 grooms')->assertSee('150')->assertSee('50.0%')
        ->assertSee($lonely->profile->code)->assertSeeHtml(route('admin.members.show', $lonely->profile->code));
});

it('A09 revenue report (P9.1b): net, ARPU, churn "settles after 30 days" note, tables by plan / month / district / bureau', function (): void {
    ReportSnapshot::query()->insert([
        ['metric' => 'revenue_paid_paise', 'day' => '2026-11-15', 'dimension' => '', 'value' => 117_882, 'computed_at' => now()],
        ['metric' => 'revenue_paid_paise', 'day' => '2026-11-15', 'dimension' => 'plan:GOLD', 'value' => 117_882, 'computed_at' => now()],
        ['metric' => 'orders_paid', 'day' => '2026-11-15', 'dimension' => '', 'value' => 1, 'computed_at' => now()],
    ]);

    Livewire::test(ReportView::class, ['report' => 'revenue'])->set('preset', '7d')->assertOk()
        ->assertSee('Net revenue')->assertSee('1,178.82')->assertSee('ARPU')->assertSee('Lifetime value')->assertSee('Churn')
        ->assertSee('Revenue by plan')->assertSee('Revenue by month')->assertSee('Paid by the buyer\'s district')->assertSee('Revenue through bureaus');
});

it('A09 trust & ops report (P9.1b): SLA numbers, backlog; moderator names only with reports.staff', function (): void {
    $anu = adminWithRole('moderator');
    $anu->forceFill(['name' => 'Anu Moderator'])->save();
    App\Models\ModerationItem::factory()->create(['status' => App\Enums\ModerationStatus::Approved, 'decided_by_admin_id' => $anu->id,
        'submitted_at' => now()->subDays(3)->utc(), 'decided_at' => now()->subDays(3)->addHour()->utc()]);

    Livewire::test(ReportView::class, ['report' => 'trust'])->set('preset', '7d')->assertOk()
        ->assertSee('Moderation within SLA')->assertSee('Moderator quality')->assertSee('Anu Moderator')->assertDontSee($anu->email);

    $this->actingAs(adminWithRole('finance'), 'admin');   // reports.view, no reports.staff
    Livewire::test(ReportView::class, ['report' => 'trust'])->set('preset', '7d')->assertOk()
        ->assertSee('Moderation team')->assertDontSee('Anu Moderator');
});

it('A09 custom range: validated on the server — reversed, future, over 400 days or garbage dates show an error, not a report', function (array $set, string $field): void {
    $page = Livewire::test(ReportView::class, ['report' => 'growth'])->set('preset', 'custom');
    foreach ($set as $k => $v) {
        $page->set($k, $v);
    }
    $page->assertHasErrors([$field])->assertSee('Choose a valid period');
})->with([
    'reversed' => [['from' => '2026-11-10', 'to' => '2026-11-01'], 'to'],
    'includes today' => [['from' => '2026-11-10', 'to' => '2026-11-20'], 'to'],
    'too long' => [['from' => '2025-01-01', 'to' => '2026-11-01'], 'to'],
    'garbage' => [['from' => "x' OR 1=1", 'to' => '2026-11-01'], 'from'],
]);

it('A09 empty period: tables say there is no data yet; an unknown preset falls back to 30 days', function (): void {
    Livewire::test(ReportView::class, ['report' => 'growth'])->assertSee('No data for this period')
        ->set('preset', 'nonsense')->assertSee('21 Oct 2026 – 19 Nov 2026');
});

it('A09 security: reports.view required (403); unknown / not-built report 404; the report key is locked', function (): void {
    $this->actingAs(adminWithRole('moderator'), 'admin');
    Livewire::test(Catalogue::class)->assertForbidden();
    Livewire::test(ReportView::class, ['report' => 'growth'])->assertForbidden();

    $this->actingAs(adminWithRole('super_admin'), 'admin');
    Livewire::test(ReportView::class, ['report' => 'nope'])->assertNotFound();
    expect(fn () => Livewire::test(ReportView::class, ['report' => 'growth'])->set('report', 'marketplace'))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    $this->actingAs(adminWithRole('read_only'), 'admin');   // can view, can't export
    Livewire::test(ReportView::class, ['report' => 'growth'])->assertOk();
    Livewire::test(Exports::class)->assertForbidden();
});

it('A09 my exports: only the admin\'s own exports; empty state', function (): void {
    Livewire::test(Exports::class)->assertSee('No exports yet');

    $other = adminWithRole();
    foreach ([[$other, 'REXOTHER00001'], [auth('admin')->user(), 'REXMINE000001']] as [$admin, $code]) {
        (new App\Models\AdminExport)->forceFill(['code' => $code, 'admin_user_id' => $admin->id, 'report' => 'growth', 'params' => ['from' => '2026-11-01', 'to' => '2026-11-07'],
            'format' => 'csv', 'status' => 'READY', 'rows' => 9, 'expires_at' => now()->addDay()])->save();
    }
    Livewire::test(Exports::class)->assertSee('REXMINE000001')->assertDontSee('REXOTHER00001');
});

it('A09 the report page runs a bounded number of queries (no N+1 as data grows)', function (string $report): void {
    seedSnapshots('2026-10-21', '2026-11-19', 5);
    Profile::factory()->count(3)->active()->create();
    App\Models\ModerationItem::factory()->count(3)->create(['status' => App\Enums\ModerationStatus::Approved, 'decided_by_admin_id' => adminWithRole('moderator')->id,
        'submitted_at' => now()->subDays(5)->utc(), 'decided_at' => now()->subDays(5)->addHour()->utc()]);

    DB::enableQueryLog();
    Livewire::test(ReportView::class, ['report' => $report])->assertOk();
    $few = count(DB::getQueryLog());
    Cache::flush();
    seedSnapshots('2026-09-01', '2026-10-20', 9);
    foreach (range(1, 4) as $i) {
        App\Models\ModerationItem::factory()->create(['status' => App\Enums\ModerationStatus::Rejected, 'decided_by_admin_id' => adminWithRole('moderator')->id,
            'submitted_at' => now()->subDays(40)->utc(), 'decided_at' => now()->subDays(40)->addHour()->utc()]);
    }
    $page = Livewire::test(ReportView::class, ['report' => $report]);
    DB::flushQueryLog();

    $page->set('preset', '90d')->assertOk();

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual($few * 2 + 5);
})->with(['growth', 'engagement', 'marketplace', 'revenue', 'trust']);   // P9.3c: all five reports
