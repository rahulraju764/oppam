<?php

declare(strict_types=1);

use App\Enums\BrokerKycStatus;
use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Enums\ConsentDisputeStatus;
use App\Enums\ImportBatchStatus;
use App\Enums\ProfileStatus;
use App\Livewire\Admin\Brokers\BrokerShow;
use App\Livewire\Admin\Brokers\Disputes;
use App\Livewire\Admin\Brokers\ExportsAudit;
use App\Livewire\Admin\Brokers\ImportsMonitor;
use App\Livewire\Admin\Brokers\Tabs\ActivityTab;
use App\Livewire\Admin\Brokers\Tabs\ProfilesTab;
use App\Livewire\Admin\Brokers\Tabs\ReferralsTab;
use App\Livewire\Admin\Brokers\Tabs\StaffTab;
use App\Models\Broker;
use App\Models\BrokerConsentDispute;
use App\Models\BrokerImportBatch;
use App\Models\BrokerProfileExport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P7.8b — A07 oversight screens (PRD §11A B.12): the BrokerShow tabs (shown per permission), the
| export audit, the imports monitor and consent disputes — each renders without lazy loading,
| authorizes on its own, keeps its bureau code #[Locked], shows its empty state and drives its Actions.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    Notification::fake();
    $this->owner = bureauLogin(BureauRole::Owner);
    $this->broker = staffOf($this->owner)->broker;
});

it('bureau page: each tab shows only for its permission; a typed ?tab the admin may not open falls back to Overview', function (): void {
    $this->actingAs(adminWithRole('support'), 'admin');                    // brokers.view only
    Livewire::test(BrokerShow::class, ['broker' => $this->broker->code])
        ->assertSeeHtml('dusk="broker-tab-disputes"')->assertSeeHtml('dusk="broker-tab-activity"')
        ->assertDontSeeHtml('dusk="broker-tab-staff"')->assertDontSeeHtml('dusk="broker-tab-exports"')->assertDontSeeHtml('dusk="broker-tab-imports"')
        ->set('tab', 'staff')->assertSet('tab', 'overview')
        ->set('tab', 'nonsense')->assertSet('tab', 'overview')
        ->set('tab', 'referrals')->assertSet('tab', 'referrals');

    $this->actingAs(adminWithRole('read_only'), 'admin');                  // every *.view key
    Livewire::test(BrokerShow::class, ['broker' => $this->broker->code])
        ->assertSeeHtml('dusk="broker-tab-staff"')->assertSeeHtml('dusk="broker-tab-exports"')->assertSeeHtml('dusk="broker-tab-imports"')
        ->assertDontSeeHtml('dusk="broker-revoke-kyc-open"');
});

it('bureau page: revoke KYC through the dialog (reason required) and pause / allow adding profiles', function (): void {
    $this->actingAs(adminWithRole('super_admin'), 'admin');
    $page = Livewire::test(BrokerShow::class, ['broker' => $this->broker->code])->assertSeeHtml('dusk="broker-revoke-kyc-open"');

    $page->call('revokeKyc')->assertHasErrors('reason');
    expect($this->broker->refresh()->kyc_status)->toBe(BrokerKycStatus::Verified);

    $page->set('reason', 'PAN belongs to someone else')->call('revokeKyc')->assertHasNoErrors()->assertDispatched('toast');
    expect($this->broker->refresh()->kyc_status)->toBe(BrokerKycStatus::Revoked);

    $page->set('reason', 'Complaints under review')->call('pauseCreation')->assertSee('Adding profiles paused');
    expect($this->broker->refresh()->creation_paused_at)->not->toBeNull();
    $page->set('reason', 'Review finished')->call('resumeCreation');
    expect($this->broker->refresh()->creation_paused_at)->toBeNull();
});

it('tabs: Referrals, Managed profiles (PENDING_REVIEW links into A04), Staff and Activity render with no lazy loading and their empty states', function (): void {
    Model::preventLazyLoading();
    $this->actingAs(adminWithRole('super_admin'), 'admin');
    $empty = Broker::factory()->create();

    Livewire::test(ReferralsTab::class, ['code' => $empty->code])->assertOk()->assertSee('No referrals');
    Livewire::test(ProfilesTab::class, ['code' => $empty->code])->assertOk()->assertSee('No managed profiles');
    Livewire::test(ActivityTab::class, ['code' => $empty->code])->assertOk()->assertSee('No activity');

    $pending = managedProfile($this->broker, ['status' => ProfileStatus::PendingReview]);
    Livewire::test(ProfilesTab::class, ['code' => $this->broker->code])->assertOk()
        ->assertSee($pending->code)->assertSeeHtml(route('admin.moderation.profiles.review', ['profile' => $pending->code]));
    Livewire::test(StaffTab::class, ['code' => $this->broker->code])->assertOk()
        ->assertSee(staffOf($this->owner)->code)->assertSee('Seats:');
});

it('tabs authorize on their own (reachable without the page) and keep the bureau code #[Locked]; unknown code 404', function (): void {
    $this->actingAs(adminWithRole('support'), 'admin');
    Livewire::test(StaffTab::class, ['code' => $this->broker->code])->assertForbidden();   // brokers.staff.view
    Livewire::test(ExportsAudit::class, ['bureau' => $this->broker->code])->assertForbidden();
    Livewire::test(ImportsMonitor::class, ['bureau' => $this->broker->code])->assertForbidden();

    $this->actingAs(adminWithRole('moderator'), 'admin');
    Livewire::test(ReferralsTab::class, ['code' => $this->broker->code])->assertForbidden();

    $this->actingAs(adminWithRole('super_admin'), 'admin');
    Livewire::test(ProfilesTab::class, ['code' => 'BRK999999'])->assertNotFound();
    expect(fn () => Livewire::test(ProfilesTab::class, ['code' => $this->broker->code])->set('code', 'BRK1'))->toThrow(CannotUpdateLockedPropertyException::class)
        ->and(fn () => Livewire::test(Disputes::class, ['bureau' => $this->broker->code])->set('bureau', ''))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('staff tab: deactivate a staff login and reset devices through the dialogs; brokers.staff.edit only', function (): void {
    $manager = bureauLogin(BureauRole::Manager, broker: $this->broker);
    $code = staffOf($manager)->code;

    $this->actingAs(adminWithRole('read_only'), 'admin');
    Livewire::test(StaffTab::class, ['code' => $this->broker->code])->assertOk()->assertDontSee('Reset devices')
        ->set('target', $code)->set('reason', 'Read-only tries')->call('deactivate')->assertForbidden();
    expect(staffOf($manager)->status)->toBe(BureauStaffStatus::Active);

    $this->actingAs(adminWithRole('super_admin'), 'admin');
    Livewire::test(StaffTab::class, ['code' => $this->broker->code])->assertSee('Reset devices')
        ->call('confirm', 'staff-deactivate', $code)->assertDispatched('open-modal')
        ->set('reason', 'Left the bureau')->call('deactivate')->assertDispatched('toast');
    expect(staffOf($manager)->status)->toBe(BureauStaffStatus::Deactivated);
});

it('export audit page: lists rows with filters, flags an unknown code, shows the empty state; embedded = one bureau only', function (): void {
    Model::preventLazyLoading();
    $this->actingAs(adminWithRole('super_admin'), 'admin');
    $mine = BrokerProfileExport::factory()->create(['broker_id' => $this->broker->id, 'user_id' => $this->owner->id, 'profile_id' => managedProfile($this->broker)->id]);
    $theirs = BrokerProfileExport::factory()->create();

    Livewire::test(ExportsAudit::class)->assertOk()->assertSee('Export audit')
        ->assertSee($this->broker->code)->assertSee(Broker::query()->whereKey($theirs->broker_id)->value('code'))
        ->set('broker', 'BRK999999')->assertSee('No such bureau')->assertSee('No exports');

    Livewire::test(ExportsAudit::class, ['bureau' => $this->broker->code])->assertOk()->assertDontSee('Every biodata and photo export by every bureau')
        ->assertSee(staffOf($this->owner)->code)->assertDontSeeHtml('id="ea-broker"');
    expect($mine->exists)->toBeTrue();
});

it('imports monitor: pause / resume a bureau and cancel a batch through the dialogs; the paused bureau is listed first', function (): void {
    $this->actingAs(adminWithRole('super_admin'), 'admin');
    $batch = BrokerImportBatch::factory()->create(['broker_id' => $this->broker->id, 'status' => ImportBatchStatus::Ready, 'rows_total' => 3]);

    $monitor = Livewire::test(ImportsMonitor::class)->assertOk()->assertSee($batch->code);
    $monitor->call('confirm', 'imports-pause', $this->broker->code)->set('reason', 'Data quality review')->call('pause')->assertDispatched('toast');
    expect($this->broker->refresh()->imports_paused_at)->not->toBeNull();
    $monitor->assertSee('Paused imports')->assertSee('Resume imports');

    $monitor->call('confirm', 'imports-cancel', $this->broker->code, $batch->code)->set('reason', 'Bureau asked to cancel')->call('cancelBatch');
    expect($batch->refresh()->status)->toBe(ImportBatchStatus::Cancelled);

    $monitor->call('confirm', 'imports-resume', $this->broker->code)->set('reason', 'Reviewed')->call('resume');
    expect($this->broker->refresh()->imports_paused_at)->toBeNull();

    Livewire::test(ImportsMonitor::class)->set('broker', 'BRK999999')->assertSee('No import batches');
});

it('imports monitor embedded in a bureau acts on that bureau only, whatever target the browser sends', function (): void {
    $this->actingAs(adminWithRole('super_admin'), 'admin');
    $other = Broker::factory()->create();

    Livewire::test(ImportsMonitor::class, ['bureau' => $this->broker->code])
        ->set('targetBroker', $other->code)->set('reason', 'Pause from the tab')->call('pause');

    expect($this->broker->refresh()->imports_paused_at)->not->toBeNull()
        ->and($other->refresh()->imports_paused_at)->toBeNull();
});

it('disputes: record from a bureau’s tab, then uphold; an unknown profile shows a field error; the list filters by status', function (): void {
    $this->actingAs(adminWithRole('super_admin'), 'admin');
    $profile = managedProfile($this->broker);

    $tab = Livewire::test(Disputes::class, ['bureau' => $this->broker->code])->assertOk()->assertSee('No disputes');
    $tab->set('newProfile', 'OPM999999')->set('note', 'Client called us')->call('record')->assertHasErrors('newProfile');

    $tab->set('newProfile', $profile->code)->set('note', 'Client called: never agreed')->call('record')->assertHasNoErrors()->assertDispatched('toast');
    $dispute = BrokerConsentDispute::query()->sole();
    $tab->assertSee($dispute->code);

    $tab->call('decide', $dispute->code)->assertDispatched('open-modal')
        ->set('outcome', 'Confirmed with the client')->call('uphold')->assertDispatched('toast');
    expect($dispute->refresh()->status)->toBe(ConsentDisputeStatus::Upheld);

    Livewire::test(Disputes::class)->assertOk()->assertSee('Consent disputes')->assertDontSee($dispute->code)   // default: open only
        ->set('status', 'UPHELD')->assertSee($dispute->code);
});

it('disputes page: support (brokers.view) reads the list but cannot record or decide', function (): void {
    $this->actingAs(adminWithRole('support'), 'admin');
    BrokerConsentDispute::factory()->create(['broker_id' => $this->broker->id]);

    Livewire::test(Disputes::class)->assertOk()->assertDontSeeHtml('dusk="dispute-record-open"')
        ->set('newBroker', $this->broker->code)->set('newProfile', managedProfile($this->broker)->code)->set('note', 'Support tries it')
        ->call('record')->assertForbidden();
});

it('disputes embedded in a bureau decide only that bureau’s disputes, whatever code the browser sends', function (): void {
    $this->actingAs(adminWithRole('super_admin'), 'admin');
    $foreign = BrokerConsentDispute::factory()->create();

    Livewire::test(Disputes::class, ['bureau' => $this->broker->code])
        ->set('target', $foreign->code)->set('outcome', 'Tampered target code')->call('uphold')
        ->assertDispatched('toast', type: 'error');

    expect($foreign->refresh()->status)->toBe(ConsentDisputeStatus::Open);
});
