<?php

declare(strict_types=1);

use App\Actions\Broker\ManagedProfiles\AddClientNote;
use App\Actions\Broker\ManagedProfiles\CompleteFollowUp;
use App\Actions\Broker\ManagedProfiles\RespondToInterestForClient;
use App\Actions\Engagement\SendInterest;
use App\Data\Broker\ClientNoteData;
use App\Enums\BureauRole;
use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Models\AuditLog;
use App\Models\ModerationItem;
use App\Queries\Broker\BureauActivity;
use App\Queries\Broker\StaffPerformance;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Database\Seeders\PlansSeeder;

/*
| P7.5 — the bureau activity log and the Owner's monthly performance report (§11A B.8): only this
| bureau's logins' `broker.*` audit rows, filters by staff / date / kind, Owner + Manager
| (`activity.view`); per-staff figures from grouped queries.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
});

function auditAs(App\Models\User $actor, string $action, string $label = 'OPM10001'): AuditLog
{
    return app(AuditLogger::class)->record($action, null, after: ['x' => 1], actor: $actor, subjectLabel: $label);
}

it('B.8: lists only this bureau’s logins’ broker.* actions — never another bureau’s, a member’s or an admin’s', function (): void {
    $owner = bureauLogin();
    $broker = staffOf($owner)->broker;
    $entry = bureauLogin(BureauRole::DataEntry, broker: $broker);
    auditAs($owner, 'broker.profile_created', 'OPM10001');
    auditAs($entry, 'broker.profile_submitted', 'OPM10002');
    auditAs(bureauLogin(), 'broker.profile_created', 'OPM20001');   // another bureau
    auditAs(groom(), 'member.something', 'OPM30001');                // a member
    auditAs($owner, 'auth.login', 'X');                              // not a bureau action

    $rows = app(BureauActivity::class)->page(staffOf($owner), []);

    expect(collect($rows->items())->pluck('subject_label')->sort()->values()->all())->toBe(['OPM10001', 'OPM10002']);
});

it('B.8: filters by staff code, IST dates and kind; unknown filter values are ignored, not trusted', function (): void {
    $owner = bureauLogin();
    $broker = staffOf($owner)->broker;
    $entry = bureauLogin(BureauRole::DataEntry, broker: $broker);

    $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00', 'Asia/Kolkata'));
    auditAs($entry, 'broker.profile_created', 'OPM1');
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Kolkata'));
    auditAs($entry, 'broker.interest_accepted', 'OPM2');
    auditAs($owner, 'broker.staff_invited', '+91 98•••••011');

    $query = fn (array $f): array => app(BureauActivity::class)->query(staffOf($owner), $f)->orderBy('id')->pluck('subject_label')->all();

    expect($query(['staff' => staffOf($entry)->code]))->toBe(['OPM1', 'OPM2'])
        ->and($query(['from' => '2026-10-10']))->toBe(['OPM2', '+91 98•••••011'])
        ->and($query(['to' => '2026-10-01']))->toBe(['OPM1'])
        ->and($query(['group' => 'inbox']))->toBe(['OPM2'])
        ->and($query(['group' => 'staff']))->toBe(['+91 98•••••011'])
        ->and($query(['staff' => staffOf(bureauLogin())->code]))->toBe([])        // another bureau's code: nothing
        ->and($query(['from' => '2026-13-45', 'group' => 'DROP TABLE']))->toHaveCount(3);
});

it('B.11: the activity page is Owner and Manager only; it renders with names and labels', function (BureauRole $role, int $status): void {
    $owner = bureauLogin();
    auditAs($owner, 'broker.profile_exported', 'OPM10009');
    actingAsBureau($role === BureauRole::Owner ? $owner : bureauLogin($role, broker: staffOf($owner)->broker));

    $response = $this->get(brokerUrl('/activity'))->assertStatus($status);
    if ($status === 200) {
        $response->assertSee('Exported a profile')->assertSee('OPM10009')->assertSee(staffOf($owner)->displayName());
    }
})->with([
    'owner' => [BureauRole::Owner, 200], 'manager' => [BureauRole::Manager, 200],
    'data entry' => [BureauRole::DataEntry, 403], 'telecaller' => [BureauRole::Telecaller, 403],
]);

it('B.8 performance: per staff per IST month — created, imported, approved, rejection rate, interests handled, follow-ups completed', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00', 'Asia/Kolkata'));
    $owner = bureauLogin();
    $broker = staffOf($owner)->broker;
    $entry = bureauLogin(BureauRole::DataEntry, broker: $broker);
    $telecaller = bureauLogin(BureauRole::Telecaller, broker: $broker);

    $a = managedProfile($broker, ['created_by_user_id' => $entry->id]);
    $b = managedProfile($broker, ['created_by_user_id' => $entry->id]);
    managedProfile($broker, ['created_by_user_id' => $entry->id, 'created_at' => now()->subMonth()]);   // last month
    foreach ([[$a, ModerationStatus::Approved], [$b, ModerationStatus::Rejected]] as [$profile, $status]) {
        ModerationItem::factory()->create(['profile_id' => $profile->id, 'type' => ModerationItemType::ProfileNew, 'status' => $status,
            'decided_at' => now(), 'decided_by_admin_id' => App\Models\AdminUser::factory()->create()->id]);
    }

    $client = managedProfile($broker, [], staffOf($telecaller));
    $suitor = groom();
    app(SendInterest::class)->handle($suitor, $client, 'T01');
    app(RespondToInterestForClient::class)->handle($telecaller, $client->code, $suitor->profile->code, false);
    $note = app(AddClientNote::class)->handle($telecaller, $client->code, new ClientNoteData('CALL', null, 'Called the father', now('Asia/Kolkata')->format('Y-m-d')));
    app(CompleteFollowUp::class)->handle($telecaller, $note->id);
    app(CompleteFollowUp::class)->handle($telecaller, $note->id);   // idempotent: counted once

    $report = app(StaffPerformance::class)->forMonth($broker, '2026-10');

    expect($report[$entry->id])->toMatchArray(['created' => 2, 'imported' => 0, 'approved' => 1, 'rejected' => 1, 'rejection_rate' => 50])
        ->and($report[$telecaller->id])->toMatchArray(['interests' => 1, 'follow_ups' => 1, 'created' => 0])
        ->and(app(StaffPerformance::class)->forMonth($broker, '2026-09')[$entry->id]['created'])->toBe(1);
});
