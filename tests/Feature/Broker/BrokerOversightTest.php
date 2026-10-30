<?php

declare(strict_types=1);

use App\Actions\Admin\Brokers\Disputes\DecideConsentDispute;
use App\Actions\Admin\Brokers\Disputes\RecordConsentDispute;
use App\Actions\Admin\Brokers\Imports\CancelImportBatchAsAdmin;
use App\Actions\Admin\Brokers\RevokeBrokerKyc;
use App\Actions\Admin\Brokers\SetBrokerCreationPause;
use App\Actions\Admin\Brokers\SetBrokerImportsPause;
use App\Actions\Admin\Brokers\Staff\DeactivateBureauStaff;
use App\Actions\Admin\Brokers\Staff\ResetStaffDevices;
use App\Domain\Broker\BureauGate;
use App\Enums\AbuseCaseStatus;
use App\Enums\BrokerKycStatus;
use App\Enums\BureauPermission;
use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Enums\ConsentDisputeStatus;
use App\Enums\DisputeSource;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportsPauseReason;
use App\Enums\ProfileOwnerType;
use App\Enums\ProfileStatus;
use App\Events\Account\ForceLogout;
use App\Exceptions\Admin\BrokerStateConflict;
use App\Exceptions\Broker\ImportRefused;
use App\Exceptions\Broker\StaffRefused;
use App\Models\AbuseCase;
use App\Models\AuditLog;
use App\Models\Broker;
use App\Models\BrokerConsentDispute;
use App\Models\BrokerImportBatch;
use App\Models\BrokerProfileExport;
use App\Models\TrustedDevice;
use App\Notifications\Broker\BrokerKycDecided;
use App\Queries\Admin\BrokerExportAudit;
use App\Queries\Broker\BureauActivity;
use App\Services\Broker\ImportFileVault;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| P7.8b — A07 oversight (PRD §11A B.12, R-A07-7/9/10, R-M13-21, B.17, B.19; decisions 2026-10-29):
| KYC revocation, consent disputes and the creation pause, admin import pause / resume / cancel,
| staff deactivation and device reset from A07, the export audit (filters, anomalies) and the
| bureau activity timeline. Each Action: its permission, its state rules, its audit row.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    Notification::fake();
    $this->admin = adminWithRole('super_admin');
    $this->owner = bureauLogin(BureauRole::Owner);
    $this->broker = staffOf($this->owner)->broker;
    $this->manager = bureauLogin(BureauRole::Manager, broker: $this->broker);
});

function gateFresh(): BureauGate
{
    $gate = app(BureauGate::class);
    $gate->forget();

    return $gate;
}

// ── KYC revocation (R-M13-21, B.19) ─────────────────────────────────────────────────────────

it('★ R-M13-21 / B.19: revoking KYC blocks create, import and export for the Owner and staff at once; profiles and logins stay', function (): void {
    $profile = managedProfile($this->broker);

    app(RevokeBrokerKyc::class)->handle($this->admin, $this->broker, 'PAN found to belong to someone else');

    $gate = gateFresh();
    foreach ([$this->owner, $this->manager] as $login) {
        foreach ([BureauPermission::ProfilesCreate, BureauPermission::ProfilesImport, BureauPermission::ProfilesExport] as $permission) {
            expect($gate->inspect($login, $permission)->code())->toBe(BureauGate::KYC_REQUIRED);
        }
        expect($gate->allows($login, BureauPermission::ProfilesView))->toBeTrue();
    }
    expect($this->broker->refresh()->kyc_status)->toBe(BrokerKycStatus::Revoked)
        ->and($this->broker->isPayable())->toBeFalse()                                        // R-A07-2: out of payout runs
        ->and($profile->refresh()->status)->toBe(ProfileStatus::Active)
        ->and(AuditLog::query()->where('action', 'brokers.kyc_revoked')->sole()->reason)->toBe('PAN found to belong to someone else');
    Notification::assertSentTo($this->owner, BrokerKycDecided::class, fn (BrokerKycDecided $n): bool => $n->outcome === BrokerKycDecided::REVOKED && $n->reason === null);
});

it('KYC revocation: only a VERIFIED KYC, brokers.kyc only, with a reason', function (): void {
    expect(fn () => app(RevokeBrokerKyc::class)->handle(adminWithRole('support'), $this->broker, 'Reason given here'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RevokeBrokerKyc::class)->handle($this->admin, $this->broker, 'no'))->toThrow(ValidationException::class);

    app(RevokeBrokerKyc::class)->handle($this->admin, $this->broker, 'PAN mismatch found');
    expect(fn () => app(RevokeBrokerKyc::class)->handle($this->admin, $this->broker, 'PAN mismatch again'))->toThrow(BrokerStateConflict::class);
});

// ── Consent disputes (R-A07-10) ─────────────────────────────────────────────────────────────

it('★ R-A07-10: recording a dispute suspends the profile, opens an A10 case and an OPEN dispute; audited', function (): void {
    $profile = managedProfile($this->broker);

    $dispute = app(RecordConsentDispute::class)->handle($this->admin, $this->broker, strtolower($profile->code), DisputeSource::Call, 'Client says she never agreed');

    $case = AbuseCase::query()->sole();
    expect($dispute->code)->toMatch('/^DSP\d{4,}$/')
        ->and($dispute->status)->toBe(ConsentDisputeStatus::Open)
        ->and($dispute->profile_status_before)->toBe(ProfileStatus::Active)
        ->and($dispute->abuse_case_id)->toBe($case->id)
        ->and($case->reported_profile_id)->toBe($profile->id)
        ->and($case->status)->toBe(AbuseCaseStatus::Open)
        ->and($profile->refresh()->status)->toBe(ProfileStatus::Suspended)
        ->and(AuditLog::query()->where('action', 'brokers.dispute_recorded')->sole()->after['case'])->toBe($case->code);
});

it('R-A07-10: only a profile this bureau manages now — another bureau’s, a claimed or a deleted one is refused; one open dispute per profile', function (): void {
    $other = managedProfile(Broker::factory()->create());
    $claimed = managedProfile($this->broker);
    $claimed->forceFill(['owner_type' => ProfileOwnerType::Self, 'claimed_at' => now()])->save();
    $record = fn (string $code) => app(RecordConsentDispute::class)->handle($this->admin, $this->broker, $code, DisputeSource::Email, 'Never agreed to it');

    expect(fn () => $record($other->code))->toThrow(BrokerStateConflict::class)
        ->and(fn () => $record($claimed->code))->toThrow(BrokerStateConflict::class)
        ->and(fn () => $record('OPM999999'))->toThrow(BrokerStateConflict::class);

    $mine = managedProfile($this->broker);
    $record($mine->code);
    expect(fn () => $record($mine->code))->toThrow(BrokerStateConflict::class)
        ->and(BrokerConsentDispute::query()->count())->toBe(1)
        ->and($other->refresh()->status)->toBe(ProfileStatus::Active);
});

it('R-A07-10: recording and deciding need brokers.edit and a 5–500 character note', function (): void {
    $profile = managedProfile($this->broker);
    expect(fn () => app(RecordConsentDispute::class)->handle(adminWithRole('finance'), $this->broker, $profile->code, DisputeSource::Call, 'Never agreed'))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(RecordConsentDispute::class)->handle($this->admin, $this->broker, $profile->code, DisputeSource::Call, 'no'))
        ->toThrow(ValidationException::class)
        ->and($profile->refresh()->status)->toBe(ProfileStatus::Active);

    $dispute = app(RecordConsentDispute::class)->handle($this->admin, $this->broker, $profile->code, DisputeSource::Call, 'Never agreed');
    expect(fn () => app(DecideConsentDispute::class)->handle(adminWithRole('finance'), $dispute->code, true, 'Checked with client'))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(DecideConsentDispute::class)->handle($this->admin, $dispute->code, true, ''))
        ->toThrow(ValidationException::class);
});

it('R-A07-10: a dismissed dispute returns the profile to its earlier status and never counts', function (): void {
    $profile = managedProfile($this->broker);
    $dispute = app(RecordConsentDispute::class)->handle($this->admin, $this->broker, $profile->code, DisputeSource::Call, 'Never agreed');

    $result = app(DecideConsentDispute::class)->handle($this->admin, $dispute->code, false, 'Client confirmed she agreed');

    expect($result['dispute']->status)->toBe(ConsentDisputeStatus::Dismissed)
        ->and($result['creation_paused'])->toBeFalse()
        ->and($profile->refresh()->status)->toBe(ProfileStatus::Active)
        ->and(fn () => app(DecideConsentDispute::class)->handle($this->admin, $dispute->code, true, 'Second decision'))->toThrow(BrokerStateConflict::class);
});

it('★ R-A07-10: the 3rd upheld dispute within 90 days pauses the bureau’s create / import rights — single create, import and nothing else', function (): void {
    $uphold = function (): array {
        $dispute = app(RecordConsentDispute::class)->handle($this->admin, $this->broker, managedProfile($this->broker)->code, DisputeSource::Call, 'Never agreed');

        return app(DecideConsentDispute::class)->handle($this->admin, $dispute->code, true, 'Client confirmed: never agreed');
    };

    // An upheld dispute decided before the window doesn't count.
    BrokerConsentDispute::factory()->upheld()->create(['broker_id' => $this->broker->id, 'decided_at' => now()->subDays(91)]);

    expect($uphold()['creation_paused'])->toBeFalse()
        ->and($uphold()['creation_paused'])->toBeFalse()
        ->and($this->broker->refresh()->creation_paused_at)->toBeNull();

    expect($uphold()['creation_paused'])->toBeTrue();

    $gate = gateFresh();
    foreach ([$this->owner, $this->manager] as $login) {
        expect($gate->inspect($login, BureauPermission::ProfilesCreate)->code())->toBe(BureauGate::CREATION_PAUSED)
            ->and($gate->inspect($login, BureauPermission::ProfilesImport)->code())->toBe(BureauGate::CREATION_PAUSED)
            ->and($gate->allows($login, BureauPermission::ProfilesExport))->toBeTrue()
            ->and($gate->allows($login, BureauPermission::ClientInboxRespond))->toBeTrue()
            ->and($gate->inspectRole($login, BureauPermission::ProfilesImport)->allowed())->toBeTrue();   // batch pages: see and cancel
    }
    expect(AuditLog::query()->where('action', 'brokers.creation_paused')->sole()->reason)->toContain('3 upheld');
});

it('R-A07-10: an admin lifts the creation pause with a reason (audited); lifting one that isn’t there is refused', function (): void {
    app(SetBrokerCreationPause::class)->handle($this->admin, $this->broker, true, 'Under review after complaints');
    expect(gateFresh()->allows($this->owner, BureauPermission::ProfilesCreate))->toBeFalse();

    app(SetBrokerCreationPause::class)->handle($this->admin, $this->broker, false, 'Review finished, data is fine');
    expect(gateFresh()->allows($this->owner, BureauPermission::ProfilesCreate))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'brokers.creation_resumed')->count())->toBe(1)
        ->and(fn () => app(SetBrokerCreationPause::class)->handle($this->admin, $this->broker, false, 'Again, not paused'))->toThrow(BrokerStateConflict::class)
        ->and(fn () => app(SetBrokerCreationPause::class)->handle(adminWithRole('finance'), $this->broker, true, 'Finance tries'))->toThrow(AuthorizationException::class);
});

// ── Imports (R-A07-9) ───────────────────────────────────────────────────────────────────────

it('★ R-A07-9: A07 pauses a bureau’s imports — single-profile creation still works; resume clears any pause', function (): void {
    app(SetBrokerImportsPause::class)->handle($this->admin, $this->broker, true, 'Data quality review');

    $this->broker->refresh();
    expect($this->broker->imports_paused_at)->not->toBeNull()
        ->and($this->broker->imports_paused_reason)->toBe(ImportsPauseReason::Admin)
        ->and(gateFresh()->allows($this->owner, BureauPermission::ProfilesCreate))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'broker.imports_paused')->sole()->reason)->toBe('Data quality review')
        ->and(fn () => app(SetBrokerImportsPause::class)->handle($this->admin, $this->broker, true, 'Pause again'))->toThrow(BrokerStateConflict::class);

    $this->broker->forceFill(['imports_paused_reason' => ImportsPauseReason::Auto])->save();   // also an auto-pause
    app(SetBrokerImportsPause::class)->handle($this->admin, $this->broker, false, 'Reviewed the rejected batch');
    expect($this->broker->refresh()->imports_paused_at)->toBeNull()
        ->and(AuditLog::query()->where('action', 'brokers.imports_resumed')->sole()->before['reason'])->toBe('AUTO')
        ->and(fn () => app(SetBrokerImportsPause::class)->handle($this->admin, $this->broker, false, 'Resume again'))->toThrow(BrokerStateConflict::class);
});

it('R-A07-9: pausing / resuming imports and cancelling a batch need brokers.imports.manage', function (): void {
    $batch = BrokerImportBatch::factory()->create(['broker_id' => $this->broker->id, 'status' => ImportBatchStatus::Ready]);

    expect(fn () => app(SetBrokerImportsPause::class)->handle(adminWithRole('finance'), $this->broker, true, 'Finance tries'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CancelImportBatchAsAdmin::class)->handle(adminWithRole('support'), $this->broker->id, $batch->code, 'Support tries'))->toThrow(AuthorizationException::class)
        ->and($batch->refresh()->status)->toBe(ImportBatchStatus::Ready);
});

it('B.12: A07 cancels a READY batch (audited as the admin); another bureau’s batch is 404; an importing batch is refused', function (): void {
    $batch = BrokerImportBatch::factory()->create(['broker_id' => $this->broker->id, 'status' => ImportBatchStatus::Ready, 'rows_total' => 12]);
    $foreign = BrokerImportBatch::factory()->create(['status' => ImportBatchStatus::Ready]);
    $busy = BrokerImportBatch::factory()->create(['broker_id' => $this->broker->id, 'status' => ImportBatchStatus::Importing]);

    app(CancelImportBatchAsAdmin::class)->handle($this->admin, $this->broker->id, $batch->code, 'Bureau asked us to cancel');

    expect($batch->refresh()->status)->toBe(ImportBatchStatus::Cancelled)
        ->and(AuditLog::query()->where('action', 'brokers.import_cancelled')->sole()->actor_id)->toBe($this->admin->id)
        ->and(fn () => app(CancelImportBatchAsAdmin::class)->handle($this->admin, $this->broker->id, $foreign->code, 'Wrong bureau'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(CancelImportBatchAsAdmin::class)->handle($this->admin, $this->broker->id, $busy->code, 'Still importing'))->toThrow(ImportRefused::class);
});

it('B.12: the source file downloads for brokers.imports.manage only — audited, 404 once purged', function (): void {
    Storage::fake((string) config('oppam.media.private_disk'));
    $batch = BrokerImportBatch::factory()->create(['broker_id' => $this->broker->id, 'status' => ImportBatchStatus::Ready]);
    $source = tempnam(sys_get_temp_dir(), 'src');
    file_put_contents($source, "first_name,last_name\nAnu,K\n");
    $path = 'imports/'.$batch->id.'/sheet.csv.enc';
    app(ImportFileVault::class)->seal($source, $path);
    @unlink($source);
    $batch->forceFill(['sheet_path' => $path])->save();
    $url = adminUrl('/brokers/'.$this->broker->code.'/imports/'.$batch->code.'/source/sheet');

    signInAdmin($this, adminWithRole('support'));
    $this->get($url)->assertForbidden();

    signInAdmin($this, $this->admin);
    $response = $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $before = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.ImportFileVault::TEMP_PREFIX.'*') ?: [];
    expect($response->streamedContent())->toContain('first_name')
        ->and(glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.ImportFileVault::TEMP_PREFIX.'*') ?: [])->toHaveCount(max(0, count($before) - 1))   // the plain copy is gone once sent
        ->and(AuditLog::query()->where('action', 'brokers.import_source_downloaded')->count())->toBe(1);

    $this->get(adminUrl('/brokers/'.$this->broker->code.'/imports/'.$batch->code.'/source/photos'))->assertNotFound();   // no zip
    $batch->forceFill(['files_purged_at' => now()])->save();
    $this->get($url)->assertNotFound();
});

// ── Staff from A07 (B.12, R-M13-24) ─────────────────────────────────────────────────────────

it('B.12: A07 deactivates a staff login — signed out live, clients unassigned, audited as the admin; the Owner’s row is refused', function (): void {
    Event::fake([ForceLogout::class]);
    $staff = staffOf($this->manager);
    $client = managedProfile($this->broker, [], $staff);

    $unassigned = app(DeactivateBureauStaff::class)->handle($this->admin, $this->broker, strtolower($staff->code), 'Left the bureau, owner unreachable');

    expect($unassigned)->toBe(1)
        ->and($staff->refresh()->status)->toBe(BureauStaffStatus::Deactivated)
        ->and($client->refresh()->assigned_staff_id)->toBeNull()
        ->and(AuditLog::query()->where('action', 'brokers.staff_deactivated')->sole()->actor_id)->toBe($this->admin->id);
    Event::assertDispatched(ForceLogout::class, fn (ForceLogout $e): bool => $e->userId === $this->manager->id);

    expect(fn () => app(DeactivateBureauStaff::class)->handle($this->admin, $this->broker, staffOf($this->owner)->code, 'Owner row attempt'))
        ->toThrow(StaffRefused::class);
});

it('B.12: staff actions are scoped to the bureau (another bureau’s staff code → 404) and need brokers.staff.edit', function (): void {
    $foreign = staffOf(bureauLogin(BureauRole::Manager));

    expect(fn () => app(DeactivateBureauStaff::class)->handle($this->admin, $this->broker, $foreign->code, 'Wrong bureau code'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(ResetStaffDevices::class)->handle($this->admin, $this->broker, $foreign->code, 'Wrong bureau code'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(DeactivateBureauStaff::class)->handle(adminWithRole('support'), $this->broker, staffOf($this->manager)->code, 'Support tries it'))->toThrow(AuthorizationException::class)
        ->and($foreign->refresh()->status)->toBe(BureauStaffStatus::Active);
});

it('B.12 / B.18: resetting devices forgets trusted devices and ends every session — the login stays active (also the Owner)', function (): void {
    Event::fake([ForceLogout::class]);
    TrustedDevice::factory()->count(2)->create(['user_id' => $this->owner->id]);
    $epoch = $this->owner->session_epoch;

    $removed = app(ResetStaffDevices::class)->handle($this->admin, $this->broker, staffOf($this->owner)->code, 'Owner lost the phone');

    expect($removed)->toBe(2)
        ->and(TrustedDevice::query()->where('user_id', $this->owner->id)->count())->toBe(0)
        ->and($this->owner->refresh()->session_epoch)->toBe($epoch + 1)
        ->and(staffOf($this->owner)->status)->toBe(BureauStaffStatus::Active)
        ->and(AuditLog::query()->where('action', 'brokers.staff_devices_reset')->count())->toBe(1);
    Event::assertDispatched(ForceLogout::class, fn (ForceLogout $e): bool => $e->userId === $this->owner->id);
});

// ── Export audit (R-A07-7) and activity ─────────────────────────────────────────────────────

it('★ R-A07-7: the export audit lists every attempt (allowed and refused) of a bureau — also a deactivated one — and can’t be changed', function (): void {
    $profile = managedProfile($this->broker);
    BrokerProfileExport::factory()->create(['broker_id' => $this->broker->id, 'user_id' => $this->owner->id, 'profile_id' => $profile->id]);
    $refused = BrokerProfileExport::factory()->create(['broker_id' => $this->broker->id, 'user_id' => $this->manager->id, 'profile_id' => managedProfile(Broker::factory()->create())->id, 'allowed' => false]);
    BrokerProfileExport::factory()->create();                                                    // another bureau
    $this->broker->forceFill(['status' => App\Enums\BrokerStatus::Deactivated])->save();

    $page = app(BrokerExportAudit::class)->page(['broker' => strtolower($this->broker->code)]);
    expect($page['rows']->total())->toBe(2)->and($page['unknown'])->toBe([]);

    $onlyRefused = app(BrokerExportAudit::class)->page(['broker' => $this->broker->code, 'allowed' => 'no', 'staff' => staffOf($this->manager)->code]);
    expect($onlyRefused['rows']->pluck('id')->all())->toBe([$refused->id]);

    expect(fn () => $refused->forceFill(['allowed' => true])->save())->toThrow(LogicException::class)
        ->and(fn () => $refused->delete())->toThrow(LogicException::class);
});

it('B.17: export-audit filters must resolve — an unknown bureau, staff or profile code lists nothing and says so', function (): void {
    BrokerProfileExport::factory()->create(['broker_id' => $this->broker->id]);

    $page = app(BrokerExportAudit::class)->page(['broker' => 'BRK999999', 'profile' => 'OPM1', 'staff' => 'STF1']);

    expect($page['unknown'])->toBe(['broker', 'profile', 'staff'])->and($page['rows']->total())->toBe(0);
});

it('B.12: unusual exports are highlighted — a burst by one login in an hour, and the same profile again within the window', function (): void {
    settingIs(App\Enums\SettingKey::BrokerExportAnomalyPerHour, 5);
    $profile = managedProfile($this->broker);
    foreach (range(1, 6) as $i) {
        BrokerProfileExport::factory()->create(['broker_id' => $this->broker->id, 'user_id' => $this->manager->id,
            'profile_id' => managedProfile($this->broker)->id, 'created_at' => now()->subMinutes(30 - $i)]);
    }
    BrokerProfileExport::factory()->create(['broker_id' => $this->broker->id, 'user_id' => $this->owner->id, 'profile_id' => $profile->id, 'created_at' => now()->subMinutes(50)]);
    $again = BrokerProfileExport::factory()->create(['broker_id' => $this->broker->id, 'user_id' => $this->owner->id, 'profile_id' => $profile->id, 'created_at' => now()->subMinutes(10)]);

    $rows = app(BrokerExportAudit::class)->page(['broker' => $this->broker->code])['rows']->keyBy('id');

    expect(BrokerExportAudit::anomaly($rows[$again->id]))->toBe('repeat')
        ->and($rows->filter(fn ($row) => BrokerExportAudit::anomaly($row) === 'burst')->count())->toBe(1)   // the 6th in the hour
        ->and($rows->filter(fn ($row) => BrokerExportAudit::anomaly($row) === null)->count())->toBe(6);
});

it('B.12 activity: the bureau’s own actions plus what A07 did to the bureau — never another bureau’s', function (): void {
    app(SetBrokerCreationPause::class)->handle($this->admin, $this->broker, true, 'Under review after complaints');
    app(App\Services\Audit\AuditLogger::class)->record('broker.profiles_assigned', $this->broker, actor: $this->manager, subjectLabel: 'OPM1');
    $other = staffOf(bureauLogin(BureauRole::Owner))->broker;
    app(SetBrokerCreationPause::class)->handle($this->admin, $other, true, 'Other bureau review');

    $rows = app(BureauActivity::class)->adminPage($this->broker, []);
    expect($rows->pluck('action')->sort()->values()->all())->toBe(['broker.profiles_assigned', 'brokers.creation_paused']);

    $staffOnly = app(BureauActivity::class)->adminPage($this->broker, ['staff' => staffOf($this->manager)->code]);
    expect($staffOnly->pluck('action')->all())->toBe(['broker.profiles_assigned']);
});

it('R-A07-10: a creation-paused bureau raises an A02 alert for brokers.view (not the imports alert set)', function (): void {
    expect(collect(app(App\Queries\Admin\DashboardAlerts::class)->for([], false, bureaus: true))->pluck('text')->implode(' '))->not->toContain('Adding profiles paused');

    app(SetBrokerCreationPause::class)->handle($this->admin, $this->broker, true, 'Under review after complaints');

    $alerts = collect(app(App\Queries\Admin\DashboardAlerts::class)->for([], false, bureaus: true));
    expect($alerts->firstWhere('level', 'danger')['text'] ?? '')->toContain('Adding profiles paused')->toContain($this->broker->code)
        ->and(collect(app(App\Queries\Admin\DashboardAlerts::class)->for([], false, brokers: true))->isEmpty())->toBeTrue();
});

it('R-A07-10 / R-M13-14: a dispute holds the profile’s waiting A04 items; dismissing puts exactly those back — never an orphaned PENDING_REVIEW', function (): void {
    $profile = managedProfile($this->broker, ['status' => ProfileStatus::PendingReview]);
    $item = App\Models\ModerationItem::factory()->create(['profile_id' => $profile->id]);

    $dispute = app(RecordConsentDispute::class)->handle($this->admin, $this->broker, $profile->code, DisputeSource::Call, 'Never agreed');
    expect($item->refresh()->status)->toBe(App\Enums\ModerationStatus::Rejected)
        ->and($item->reason_category)->toBe(App\Enums\ModerationHold::ConsentDispute->value)                       // out of the queue
        ->and(App\Models\ModerationItem::query()->where('status', App\Enums\ModerationStatus::Open)->count())->toBe(0);

    app(DecideConsentDispute::class)->handle($this->admin, $dispute->code, false, 'Client agreed after all');

    expect($profile->refresh()->status)->toBe(ProfileStatus::PendingReview)
        ->and($item->refresh()->status)->toBe(App\Enums\ModerationStatus::Open)
        ->and($item->reason_category)->toBeNull();
});

it('R-A07-10: a PENDING_REVIEW profile with nothing left to review goes back to DRAFT on dismissal (the bureau submits it again)', function (): void {
    $profile = managedProfile($this->broker, ['status' => ProfileStatus::PendingReview]);   // no moderation item

    $dispute = app(RecordConsentDispute::class)->handle($this->admin, $this->broker, $profile->code, DisputeSource::Call, 'Never agreed');
    app(DecideConsentDispute::class)->handle($this->admin, $dispute->code, false, 'Client agreed after all');

    expect($profile->refresh()->status)->toBe(ProfileStatus::Draft);
});

it('R-A07-10: upholding keeps the held items out of the queues; the Owner is told when adding is paused and allowed again', function (): void {
    $profile = managedProfile($this->broker, ['status' => ProfileStatus::PendingReview]);
    $item = App\Models\ModerationItem::factory()->create(['profile_id' => $profile->id]);
    $dispute = app(RecordConsentDispute::class)->handle($this->admin, $this->broker, $profile->code, DisputeSource::Call, 'Never agreed');

    app(DecideConsentDispute::class)->handle($this->admin, $dispute->code, true, 'Confirmed: never agreed');
    expect($item->refresh()->status)->toBe(App\Enums\ModerationStatus::Rejected)->and($profile->refresh()->status)->toBe(ProfileStatus::Suspended);

    app(SetBrokerCreationPause::class)->handle($this->admin, $this->broker, true, 'Under review after complaints');
    app(SetBrokerCreationPause::class)->handle($this->admin, $this->broker, false, 'Review finished, data fine');
    Notification::assertSentTo($this->owner, App\Notifications\Broker\BureauCreationPaused::class, fn ($n): bool => $n->paused === true);
    Notification::assertSentTo($this->owner, App\Notifications\Broker\BureauCreationPaused::class, fn ($n): bool => $n->paused === false);
    Notification::assertNotSentTo($this->manager, App\Notifications\Broker\BureauCreationPaused::class);
});

it('B.12 activity: A07 actions on the bureau’s staff, batches and disputes are in the timeline; another bureau’s are not', function (): void {
    Event::fake([ForceLogout::class]);
    app(DeactivateBureauStaff::class)->handle($this->admin, $this->broker, staffOf($this->manager)->code, 'Left the bureau for good');
    $batch = BrokerImportBatch::factory()->create(['broker_id' => $this->broker->id, 'status' => ImportBatchStatus::Ready]);
    app(CancelImportBatchAsAdmin::class)->handle($this->admin, $this->broker->id, $batch->code, 'Bureau asked to cancel');
    app(RecordConsentDispute::class)->handle($this->admin, $this->broker, managedProfile($this->broker)->code, DisputeSource::Call, 'Never agreed');

    $otherOwner = bureauLogin(BureauRole::Owner);
    $otherBroker = staffOf($otherOwner)->broker;
    app(DeactivateBureauStaff::class)->handle($this->admin, $otherBroker, staffOf(bureauLogin(BureauRole::Manager, broker: $otherBroker))->code, 'Other bureau action');

    $actions = app(BureauActivity::class)->adminPage($this->broker, [])->pluck('action')->all();
    expect($actions)->toContain('brokers.staff_deactivated')->toContain('brokers.import_cancelled')->toContain('brokers.dispute_recorded')
        ->and(collect($actions)->filter(fn ($a) => $a === 'brokers.staff_deactivated')->count())->toBe(1);
});
