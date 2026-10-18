<?php

declare(strict_types=1);

use App\Contracts\PresenceCounter;
use App\Enums\InterestStatus;
use App\Enums\Metric;
use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Members\Show as MemberShow;
use App\Livewire\Admin\Members\Tabs\EngagementTab;
use App\Models\AccessRequest;
use App\Models\AdminUser;
use App\Models\Favorite;
use App\Models\Interest;
use App\Models\Like;
use App\Models\MetricSnapshot;
use App\Models\ModerationItem;
use App\Models\Profile;
use App\Queries\Admin\WorkQueues;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| P3.5 — the admin dashboard (PRD A02): KPIs from snapshots with change, definition and "as of";
| online now; work-queue cards with count + oldest item; the alert strip; charts; recent activity —
| each section only for the permission that covers it. Live sidebar badges (§11.0) and the A03
| member page's Engagement tab (favourites as a number only, R-M06-6).
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    $this->seed(PlansSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-11 15:00', 'Asia/Kolkata'));
    app()->instance(PresenceCounter::class, new class implements PresenceCounter
    {
        public function online(): ?int
        {
            return 42;
        }
    });
});

function dashAs(string $role, bool $stripRoles = false): AdminUser
{
    $admin = adminWithRole($role);
    if ($stripRoles) {
        $admin->syncRoles([]);
        app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
    test()->actingAs($admin, 'admin');

    return $admin;
}

function dashSnapshot(Metric $metric, int $value, string $day = '2026-10-11'): void
{
    $row = new MetricSnapshot;
    $row->forceFill(['metric' => $metric, 'day' => $day, 'value' => $value, 'computed_at' => CarbonImmutable::parse('2026-10-11 14:05', 'Asia/Kolkata')->utc()])->save();
}

// ---- KPI row -------------------------------------------------------------------------------------

it('A02: KPI tiles come from snapshots, with the change, the definition and "as of" — never a live count', function (): void {
    dashSnapshot(Metric::TotalMembers, 1200);
    dashSnapshot(Metric::TotalMembers, 1000, '2026-09-11');
    dashSnapshot(Metric::NewMembers, 12);
    dashSnapshot(Metric::Likes, 340);
    App\Models\User::factory()->count(5)->create();   // live rows: must not change the tiles
    dashAs('super_admin');

    DB::enableQueryLog();
    Livewire::test(Dashboard::class)
        ->assertSee('Figures as of 11 Oct 2026, 2:05 pm IST')
        ->assertSeeInOrder(['Total members', '1,200', '+20%'])
        ->assertSeeInOrder(['New today', '12'])
        ->assertSeeInOrder(['Likes today', '340'])
        ->assertSee('Member accounts that are not deleted.')          // the definition (tooltip + screen readers)
        ->assertSeeInOrder(['Paid members', '—'])                     // no snapshot yet: a dash, not a zero
        ->assertSeeInOrder(['Online now', '42'])
        ->assertSeeHtml('dashboard-tick.window');                     // "New today" follows the live tick

    $queries = collect(DB::getQueryLog())->pluck('query');
    expect($queries->filter(fn (string $q): bool => str_contains($q, 'from `users`') && str_contains($q, 'count(')))->toHaveCount(0)
        ->and($queries->filter(fn (string $q): bool => str_contains($q, 'from `metric_snapshots`')))->toHaveCount(1);
});

it('before the first snapshot the page says so; an unreachable live server shows a dash', function (): void {
    app()->instance(PresenceCounter::class, new class implements PresenceCounter
    {
        public function online(): ?int
        {
            return null;
        }
    });
    dashAs('super_admin');

    Livewire::test(Dashboard::class)
        ->assertSee('The first figures appear after the next hourly update.')
        ->assertSee('Live server not reachable');
});

it('charts carry labels and numbers only, each with a data table for screen readers', function (): void {
    dashSnapshot(Metric::NewMembers, 12);
    dashSnapshot(Metric::InterestsSent, 9);
    dashSnapshot(Metric::InterestsAccepted, 4);
    dashSnapshot(Metric::ActiveMale, 60);
    dashSnapshot(Metric::ActiveFemale, 40);
    dashAs('super_admin');

    Livewire::test(Dashboard::class)
        ->assertSee('Signups & approved profiles (30 days)')->assertSee('Interests sent vs accepted (30 days)')->assertSee('Active profiles by gender')
        ->assertSeeHtml('x-data="adminChart(')->assertSeeHtml('wire:ignore')
        ->assertSeeHtml('<caption>Active profiles by gender</caption>')
        ->assertSeeInOrder(['Grooms', '60', 'Brides', '40']);
});

// ---- Permissions -----------------------------------------------------------------------------------

it('an admin without dashboard.view gets no figures — only the queues their role covers, or a welcome', function (): void {
    dashSnapshot(Metric::TotalMembers, 1200);
    ModerationItem::factory()->create();

    dashAs('finance', stripRoles: true);
    Livewire::test(Dashboard::class)
        ->assertSee('Your tools are in the menu')
        ->assertDontSee('Key figures')->assertDontSee('1,200')->assertDontSee('Work queues')->assertDontSee('Recent activity');

    // Every seeded role has dashboard.view; a moderator sees the queues too, but not the audit feed.
    dashAs('moderator');
    Livewire::test(Dashboard::class)->assertSee('Key figures')->assertSee('Work queues')->assertDontSee('Recent activity');

    dashAs('finance');
    Livewire::test(Dashboard::class)->assertSee('Key figures')->assertDontSee('Work queues')->assertDontSee('Recent activity');
});

it('the dashboard needs a signed-in admin', function (): void {
    $this->get(adminUrl('/'))->assertRedirect(adminUrl('/login'));
});

// ---- Work queues, alerts, activity -------------------------------------------------------------------

it('A02 work-queue cards: how many wait and how long the oldest has waited; past the SLA it is marked and alerted', function (): void {
    ModerationItem::factory()->count(2)->create(['submitted_at' => now()->subHours(3)]);
    ModerationItem::factory()->create(['type' => ModerationItemType::Photo, 'submitted_at' => now()->subHours(30)]);
    ModerationItem::factory()->create(['type' => ModerationItemType::ProfileEdit, 'status' => ModerationStatus::Approved]);   // decided: not waiting
    ModerationItem::factory()->create(['status' => ModerationStatus::Escalated, 'submitted_at' => now()->subHours(5)]);
    dashAs('moderator');

    Livewire::test(Dashboard::class)
        ->assertSeeInOrder(['Profiles to moderate', '3', 'Oldest waiting 5 hours'])     // open + escalated still wait
        ->assertSeeInOrder(['Photos', '1', 'Oldest waiting 1 day', 'Past target'])
        ->assertSeeInOrder(['Edited fields', '0', 'Nothing waiting'])
        ->assertSeeInOrder(['Escalations', '1'])
        ->assertSee('Photos: the oldest item has waited 1 day — past the 24-hour target.')
        ->assertSeeHtml('data-queue-count="moderation.profiles"');
});

it('A02 alerts: failed background jobs in the last 24 hours', function (): void {
    foreach ([now()->subHours(2), now()->subDays(3)] as $failedAt) {
        DB::table('failed_jobs')->insert(['uuid' => (string) Illuminate\Support\Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => '{}', 'exception' => 'x', 'failed_at' => $failedAt]);
    }
    dashAs('super_admin');

    Livewire::test(Dashboard::class)->assertSee('1 background job failed in the last 24 hours.');
});

it('system alerts (failed jobs, backlog) need dashboard.view; a moderator without it still gets the queue alert', function (): void {
    DB::table('failed_jobs')->insert(['uuid' => (string) Illuminate\Support\Str::uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subHour()]);
    ModerationItem::factory()->create(['submitted_at' => now()->subHours(30)]);
    $admin = dashAs('moderator');
    $admin->syncPermissions([]);
    $admin->syncRoles([]);
    $admin->givePermissionTo('moderation.view');
    app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    test()->actingAs($admin->fresh(), 'admin');

    Livewire::test(Dashboard::class)
        ->assertSee('Work queues')->assertSee('past the 24-hour target')
        ->assertDontSee('background job failed')->assertDontSee('Key figures');
});

it('§11.0: the profile and edited-fields queues listen for live counts and for claims ("being reviewed by …")', function (string $component): void {
    dashAs('moderator');

    expect(Livewire::test($component)->instance()->getListeners())
        ->toHaveKey('echo-private:admin.queues,.queue.count')->toHaveKey('echo-private:admin.queues,.queue.claim');
})->with(['profile queue' => [App\Livewire\Admin\Moderation\ProfileQueue::class], 'edited fields' => [App\Livewire\Admin\Moderation\EditedFieldsQueue::class]]);

it('recent activity is the audit log, newest first, only for admins with audit.view', function (): void {
    $admin = dashAs('super_admin');
    app(AuditLogger::class)->record('members.suspended', null, reason: 'Test one', actor: $admin, subjectLabel: 'OPM10001');
    $this->travel(1)->minutes();
    app(AuditLogger::class)->record('members.reactivated', null, reason: 'Test two', actor: $admin, subjectLabel: 'OPM10002');

    Livewire::test(Dashboard::class)->assertSee('Recent activity')
        ->assertSeeInOrder(['members.reactivated', 'OPM10002', 'members.suspended', 'OPM10001'])
        ->assertDontSee('Test one');   // reasons stay in the audit viewer
});

it('listens for live queue counts only when the admin may join admin.queues', function (): void {
    dashAs('moderator');
    expect(Livewire::test(Dashboard::class)->instance()->getListeners())->toHaveKey('echo-private:admin.queues,.queue.count');

    dashAs('finance');
    expect(Livewire::test(Dashboard::class)->instance()->getListeners())->toBe([]);
});

// ---- Sidebar badges (PRD §11.0) -----------------------------------------------------------------------

it('§11.0: the sidebar shows each moderation queue\'s waiting count and switches the live channels on per permission', function (): void {
    ModerationItem::factory()->count(3)->create();
    ModerationItem::factory()->create(['type' => ModerationItemType::Photo]);

    signInAdmin($this, adminWithRole('moderator'));
    $this->get(adminUrl('/'))->assertOk()
        ->assertSee('<meta name="oppam-realtime" content="on">', false)
        ->assertSee('<meta name="oppam-admin-queues" content="on">', false)
        ->assertSee('<meta name="oppam-admin-dashboard" content="on">', false)
        ->assertSeeInOrder(['Profile queue', 'data-queue="moderation.profiles">3<', 'Photo queue', 'data-queue="moderation.photos">1<'], false)
        ->assertDontSee('data-queue="escalations"', false);   // nothing escalated: no badge

    signInAdmin($this, adminWithRole('finance'));
    $this->get(adminUrl('/'))->assertOk()
        ->assertSee('<meta name="oppam-admin-queues" content="off">', false)
        ->assertDontSee('admin-nav-badge', false);
});

it('the queue counts for the sidebar and the dashboard are read once per request, in ONE query', function (): void {
    ModerationItem::factory()->count(2)->create();
    ModerationItem::factory()->create(['status' => ModerationStatus::Escalated, 'type' => ModerationItemType::Photo]);
    $admin = adminWithRole('moderator');
    $queues = app(WorkQueues::class);

    DB::enableQueryLog();
    $badges = $queues->badges($admin);
    $cards = $queues->for($admin);
    $moderation = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $q): bool => str_contains($q, 'moderation_items'));

    // Four A04 queues + the A13 flagged messages (moderators hold chat.view_flagged, P4.3a).
    expect($moderation)->toHaveCount(1)->and($cards)->toHaveCount(5)
        ->and($badges['admin.chat.flags'])->toBe(['key' => 'chat.flags', 'count' => 0])
        ->and($badges['admin.moderation.profiles'])->toBe(['key' => 'moderation.profiles', 'count' => 2])
        ->and($badges['admin.moderation.photos']['count'])->toBe(1)            // an escalated photo still waits in its queue
        ->and($badges['admin.moderation.escalations'])->toBe(['key' => null, 'count' => 1])
        ->and($queues->for(adminWithRole('finance')))->toBe([])->and($queues->for(null))->toBe([]);
});

// ---- A03 Engagement tab --------------------------------------------------------------------------------

it('A03 Engagement tab: likes and interests in numbers, interests listed by the other member\'s code — favourites only as a number (R-M06-6)', function (): void {
    $member = groom();
    [$liked, $bookmarked, $sentTo, $from] = [bride(), bride('+919800000049'), bride('+919800000048'), bride('+919800000047')];
    Like::factory()->between($member->profile, $liked->profile)->create();
    Like::factory()->between($from->profile, $member->profile)->create();
    Like::factory()->between($bookmarked->profile, $member->profile)->create();
    $favorite = Favorite::factory()->between($member->profile, $bookmarked->profile)->create(['note' => 'Private note about her']);
    Interest::factory()->between($member->profile, $sentTo->profile)->create(['message' => 'A private hello']);
    Interest::factory()->between($from->profile, $member->profile)->accepted()->create();
    AccessRequest::factory()->between($from->profile, $member->profile)->create();
    dashAs('super_admin');

    Livewire::test(EngagementTab::class, ['code' => $member->profile->code])
        ->assertSeeInOrder(['Likes given', '1', 'Likes received', '2', 'Interests sent', '1', 'Interests received', '1'])
        ->assertSeeInOrder(['Connections', '1'])->assertSeeInOrder(['Favourites', '1', 'A number only'])
        ->assertSee($sentTo->profile->code)->assertSee($from->profile->code)
        ->assertSee(InterestStatus::Accepted->label())
        ->assertDontSee($bookmarked->profile->code)          // whom she bookmarked is never shown
        ->assertDontSee('Private note about her')->assertDontSee('A private hello');

    expect($favorite->exists)->toBeTrue();
});

it('A03 Engagement tab needs members.view, lists 15 a page without a query per row, and is on the member page', function (): void {
    $member = groom();
    foreach (range(1, 16) as $i) {
        Interest::factory()->between(Profile::factory()->female()->active()->create(), $member->profile)->create(['sent_at' => now()->subMinutes($i)]);
    }

    dashAs('moderator');
    DB::enableQueryLog();
    $tab = Livewire::test(EngagementTab::class, ['code' => $member->profile->code]);
    $profileLookups = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $q): bool => str_starts_with($q, 'select * from `profiles` where `profiles`.`id`'));
    expect($profileLookups)->toHaveCount(0);

    $oldest = Profile::query()->findOrFail(Interest::query()->orderBy('sent_at')->value('from_profile_id'));
    $tab->assertDontSee($oldest->code)->call('gotoPage', 2, 'engagement')->assertSee($oldest->code);

    Livewire::test(MemberShow::class, ['profile' => $member->profile->code])->assertSee('Engagement')
        ->set('tab', 'engagement')->assertSet('tab', 'engagement');

    dashAs('finance');
    Livewire::test(EngagementTab::class, ['code' => $member->profile->code])->assertForbidden();
});
