<?php

declare(strict_types=1);

use App\Actions\Broker\Import\AttestBatchConsent;
use App\Actions\Broker\Import\CancelImportBatch;
use App\Actions\Broker\Import\StartBatchImport;
use App\Actions\Broker\Import\StoreImportBatch;
use App\Actions\Broker\ManagedProfiles\AnnounceModerationDecision;
use App\Domain\Broker\BureauGate;
use App\Domain\Broker\Import\ImportAllowance;
use App\Enums\BrokerKycStatus;
use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Enums\Flag;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportRowStatus;
use App\Enums\ImportsPauseReason;
use App\Enums\ImportStopReason;
use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Enums\PhotoStatus;
use App\Enums\ProfileOwnerType;
use App\Enums\ProfileStatus;
use App\Enums\SettingKey;
use App\Events\Broker\ImportCompleted;
use App\Events\Broker\ImportsPaused;
use App\Exceptions\Broker\ImportRefused;
use App\Jobs\Broker\ImportBatchRows;
use App\Models\AuditLog;
use App\Models\BrokerImportBatch;
use App\Models\BrokerStaff;
use App\Models\Media;
use App\Models\ModerationItem;
use App\Models\Profile;
use App\Models\User;
use App\Queries\Admin\DashboardAlerts;
use Database\Seeders\PlansSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
| P7.7 ★ — the import pipeline (§11A B.9 steps 4–6, auto-pause; R-M13-26…33, R-A07-9; decisions
| 2026-10-28): consent, ImportBatchRows through the single form's write path, duplicates found at
| import time, the three caps, idempotent re-runs, the stops (bureau / uploader), photos, auto-pause.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    flagOn(Flag::BrokerBulkImport);
    fakeMediaDisks();
    Queue::fake();
});

it('★ B.9 step 5 / R-M13-28 / R-M13-30: VALID + WARNING rows become DRAFT managed profiles through the form’s write path; ERROR rows are skipped; COMPLETED live', function (): void {
    Event::fake([ImportCompleted::class]);
    $login = bureauLogin(BureauRole::DataEntry);
    $batch = pipelineReady($login, [
        importRow(['about' => 'Teacher in Kochi who loves Carnatic music, reading and family trips.', 'city' => 'Kakkanad']),
        importRow(['first_name' => 'Meera', 'photo_files' => 'meera.jpg'], '9876500102'),   // WARNING: no zip
        importRow(['first_name' => 'Devi', 'height' => 'tall'], '9876500103'),               // ERROR
    ]);
    expect([$batch->rows_valid, $batch->rows_warning, $batch->rows_error])->toBe([1, 1, 1]);

    $batch = importNow($login, $batch);

    expect($batch->status)->toBe(ImportBatchStatus::Completed)
        ->and([$batch->rows_imported, $batch->rows_skipped])->toBe([2, 1])
        ->and($batch->consent_attested_by_user_id)->toBe($login->id)
        ->and(rowAt($batch, 4)->status)->toBe(ImportRowStatus::Skipped);

    $profiles = Profile::query()->where('import_batch_id', $batch->id)->with('educationCareer')->orderBy('first_name')->get();
    expect($profiles)->toHaveCount(2);
    foreach ($profiles as $profile) {
        expect($profile->status)->toBe(ProfileStatus::Draft)
            ->and($profile->published_at)->toBeNull()
            ->and($profile->owner_type)->toBe(ProfileOwnerType::BrokerManaged)
            ->and($profile->managed_by_broker_id)->toBe(staffOf($login)->broker_id)
            ->and($profile->created_by_user_id)->toBe($login->id)
            ->and($profile->consent_confirmed_by_user_id)->toBe($login->id)
            ->and($profile->broker_consent_confirmed_at)->not->toBeNull()
            ->and($profile->educationCareer?->current_district_id)->toBe(keralaDistrictId());
    }
    $anjali = $profiles->firstWhere('first_name', 'Anjali');
    expect($anjali->about)->toBe('Teacher in Kochi who loves Carnatic music, reading and family trips.')
        ->and($anjali->educationCareer?->current_city)->toBe('Kakkanad')
        ->and(rowAt($batch, 2)->imported_profile_id)->toBe($anjali->id);

    // The single form's write path: its audit row, with the batch.
    expect(AuditLog::query()->where('action', 'broker.profile_created')->where('after->batch', $batch->code)->count())->toBe(2);
    Event::assertDispatched(ImportCompleted::class, fn (ImportCompleted $e): bool => $e->batchCode === $batch->code && $e->imported === 2 && $e->skipped === 1 && $e->stopped === null);
});

it('★ R-M13-29 duplicates: a mobile that reached Oppam after the check → ERROR at import, no profile; one repeated in the file is never imported', function (): void {
    $login = bureauLogin(BureauRole::DataEntry);
    $batch = pipelineReady($login, [
        importRow([], '9876500101'),
        importRow(['first_name' => 'Twin'], '9876500102'),
        importRow(['first_name' => 'Twin2'], '9876500102'),
    ]);
    expect([$batch->rows_valid, $batch->rows_error])->toBe([1, 2]);

    User::factory()->create(['phone' => '+919876500101']);   // registered meanwhile
    $batch = importNow($login, $batch);

    expect(Profile::query()->where('import_batch_id', $batch->id)->count())->toBe(0)
        ->and($batch->rows_imported)->toBe(0)
        ->and(Profile::query()->whereIn('client_phone', ['+919876500101', '+919876500102'])->exists())->toBeFalse()
        ->and($batch->status)->toBe(ImportBatchStatus::Completed)
        ->and(rowAt($batch, 2)->status)->toBe(ImportRowStatus::Skipped)
        ->and(collect(rowAt($batch, 2)->messages)->pluck('field'))->toContain('client_mobile');
});

it('★ B.9 duplicates: a same-name-and-DOB WARNING row is still imported', function (): void {
    $login = bureauLogin(BureauRole::DataEntry);
    Profile::factory()->brokerManaged(staffOf($login)->broker)->create([
        'first_name' => 'Anjali', 'last_name' => 'Nair', 'dob' => now(config('oppam.display_timezone'))->subYears(26)->toDateString(),
    ]);
    $batch = pipelineReady($login, [importRow()]);
    expect($batch->rows_warning)->toBe(1);

    expect(importNow($login, $batch)->rows_imported)->toBe(1);
});

it('★ R-M13-27 consent: no import without the attestation; only the uploader attests and imports; a row without consent YES is never imported', function (): void {
    $login = bureauLogin(BureauRole::DataEntry);
    $batch = pipelineReady($login, [importRow(), importRow(['first_name' => 'Meera', 'client_consent' => 'NO'], '9876500102')]);
    expect(rowAt($batch, 3)->status)->toBe(ImportRowStatus::Error);

    expect(fn () => app(StartBatchImport::class)->handle($login, $batch->code))
        ->toThrow(ImportRefused::class, 'Confirm that every client');

    $owner = bureauLogin(BureauRole::Owner, broker: staffOf($login)->broker);
    app(BureauGate::class)->forget();
    expect(fn () => app(AttestBatchConsent::class)->handle($owner, $batch->code))->toThrow(ImportRefused::class, 'Only the person who uploaded');
    app(BureauGate::class)->forget();
    app(AttestBatchConsent::class)->handle($login, $batch->code);
    app(BureauGate::class)->forget();
    expect(fn () => app(StartBatchImport::class)->handle($owner, $batch->code))->toThrow(ImportRefused::class, 'Only the person who uploaded');

    app(BureauGate::class)->forget();
    app(StartBatchImport::class)->handle($login, $batch->code);
    $batch = runImportJob($batch);

    expect($batch->rows_imported)->toBe(1)
        ->and(Profile::query()->where('client_phone', '+919876500102')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'broker.import_consent_attested')->where('actor_id', $login->id)->exists())->toBeTrue();
});

it('★ R-M13-31 caps: over today’s allowance the import stops at the cap (READY, DAILY_CAP) and resumes tomorrow', function (): void {
    settingIs(SettingKey::BrokerImportRowsPerDayNew, 2);
    $login = bureauLogin(BureauRole::DataEntry);
    $batch = pipelineReady($login, [importRow(), importRow(['first_name' => 'Bindu'], '9876500102'), importRow(['first_name' => 'Chitra'], '9876500103')]);

    $batch = importNow($login, $batch);
    expect($batch->status)->toBe(ImportBatchStatus::Ready)
        ->and($batch->stop_reason)->toBe(ImportStopReason::DailyCap)
        ->and($batch->rows_imported)->toBe(2)
        ->and($batch->rows_valid)->toBe(1);

    app(BureauGate::class)->forget();
    expect(fn () => app(StartBatchImport::class)->handle($login, $batch->code))->toThrow(ImportRefused::class, 'today’s import allowance');

    $this->travelTo(now(config('oppam.display_timezone'))->addDay()->startOfDay()->addHours(9));
    app(BureauGate::class)->forget();
    app(StartBatchImport::class)->handle($login, $batch->code);
    $batch = runImportJob($batch);

    expect($batch->status)->toBe(ImportBatchStatus::Completed)
        ->and($batch->rows_imported)->toBe(3)
        ->and(Profile::query()->where('import_batch_id', $batch->id)->count())->toBe(3);
});

it('★ R-M13-31: a NEW bureau gets the new-bureau cap until it is 30 days past KYC, has 20 moderated profiles and ≥ 80 % approved', function (): void {
    $login = bureauLogin(BureauRole::Owner);
    $broker = staffOf($login)->broker;
    $allowance = app(ImportAllowance::class);

    expect($allowance->dailyCap($broker))->toBe(100);   // KYC approved today

    $broker->forceFill(['activated_at' => now()->subDays(60)])->save();
    expect($allowance->dailyCap($broker->refresh()))->toBe(100);   // no moderated profiles yet

    $profiles = Profile::factory()->count(20)->brokerManaged($broker)->create();
    $profiles->each(fn (Profile $p, int $i) => ModerationItem::factory()->create([
        'type' => ModerationItemType::ProfileNew, 'profile_id' => $p->id, 'decided_at' => now(),
        'status' => $i < 15 ? ModerationStatus::Approved : ModerationStatus::Rejected,   // 75 %
    ]));
    expect($allowance->dailyCap($broker))->toBe(100);

    ModerationItem::query()->where('profile_id', $profiles[15]->id)->update(['status' => ModerationStatus::Approved->value]);   // 80 %
    expect($allowance->dailyCap($broker))->toBe(1000);

    // The lower of the bureau's own cap (A07) and the global one (A15).
    $broker->forceFill(['import_daily_cap' => 50])->save();
    expect($allowance->dailyCap($broker->refresh()))->toBe(50);
    $broker->forceFill(['import_daily_cap' => 5000])->save();
    settingIs(SettingKey::BrokerImportRowsPerDay, 700);
    expect($allowance->dailyCap($broker->refresh()))->toBe(700);
});

it('★ R-M13-31: the per-file row limit comes from the A15 setting', function (): void {
    settingIs(SettingKey::BrokerImportRowsPerFile, 2);
    $batch = pipelineReady(bureauLogin(), [importRow(), importRow([], '9876500102'), importRow([], '9876500103')]);

    expect($batch->status)->toBe(ImportBatchStatus::Failed)->and($batch->error_message)->toContain('at most 2 rows');
});

it('★ R-M13-33 idempotent: a re-run after a crash imports each row once', function (): void {
    $login = bureauLogin(BureauRole::DataEntry);
    $batch = pipelineReady($login, [importRow(), importRow(['first_name' => 'Bindu'], '9876500102')]);

    // The first row is imported, then the run ends early (each row is its own transaction) …
    settingIs(SettingKey::BrokerImportRowsPerDayNew, 1);
    $batch = importNow($login, $batch);
    $first = rowAt($batch, 2)->imported_profile_id;
    expect($first)->not->toBeNull()->and($batch->rows_imported)->toBe(1);

    // … and the job is delivered again while the batch still says IMPORTING (a retry, a double dispatch).
    settingIs(SettingKey::BrokerImportRowsPerDayNew, 100);
    $batch->forceFill(['status' => ImportBatchStatus::Importing])->save();
    $batch = runImportJob($batch);
    $batch->forceFill(['status' => ImportBatchStatus::Importing])->save();
    runImportJob($batch);

    expect(Profile::query()->where('import_batch_id', $batch->id)->count())->toBe(2)
        ->and(rowAt($batch, 2)->imported_profile_id)->toBe($first)
        ->and($batch->refresh()->rows_imported)->toBe(2);
});

it('★ R-M13-33: a dying worker leaves the batch READY to resume', function (): void {
    $login = bureauLogin(BureauRole::DataEntry);
    $batch = pipelineReady($login, [importRow()]);
    app(AttestBatchConsent::class)->handle($login, $batch->code);
    app(StartBatchImport::class)->handle($login, $batch->code);

    (new ImportBatchRows($batch->id))->failed(new RuntimeException('timeout'));

    expect($batch->refresh()->status)->toBe(ImportBatchStatus::Ready)->and($batch->error_message)->toContain('Press Import to continue');
});

it('★ decisions 2026-10-28: the uploader losing access mid-import → the rest is SKIPPED and the batch COMPLETED (no permission bypass)', function (): void {
    $login = bureauLogin(BureauRole::DataEntry);
    $batch = pipelineReady($login, [importRow(), importRow(['first_name' => 'Bindu'], '9876500102')]);
    app(AttestBatchConsent::class)->handle($login, $batch->code);
    app(StartBatchImport::class)->handle($login, $batch->code);

    BrokerStaff::query()->where('user_id', $login->id)->update(['status' => BureauStaffStatus::Deactivated->value]);
    $batch = runImportJob($batch);

    expect($batch->status)->toBe(ImportBatchStatus::Completed)
        ->and($batch->stop_reason)->toBe(ImportStopReason::UploaderRemoved)
        ->and($batch->rows_imported)->toBe(0)
        ->and($batch->rows_skipped)->toBe(2)
        ->and(Profile::query()->where('import_batch_id', $batch->id)->exists())->toBeFalse();
});

it('★ B.9 / R-M13-21: KYC revoked → the run stops (READY, BUREAU_BLOCKED), importing is refused and the batch can only be cancelled', function (): void {
    $login = bureauLogin(BureauRole::DataEntry);
    $batch = pipelineReady($login, [importRow()]);
    app(AttestBatchConsent::class)->handle($login, $batch->code);
    app(StartBatchImport::class)->handle($login, $batch->code);

    staffOf($login)->broker->forceFill(['kyc_status' => BrokerKycStatus::Revoked])->save();
    $batch = runImportJob($batch);

    expect($batch->status)->toBe(ImportBatchStatus::Ready)
        ->and($batch->stop_reason)->toBe(ImportStopReason::BureauBlocked)
        ->and(Profile::query()->where('import_batch_id', $batch->id)->exists())->toBeFalse();

    app(BureauGate::class)->forget();
    expect(fn () => app(StartBatchImport::class)->handle($login, $batch->code))
        ->toThrow(fn (HttpException $e) => expect($e->getHeaders()['X-Error-Code'] ?? null)->toBe(BureauGate::KYC_REQUIRED));

    app(BureauGate::class)->forget();
    expect(app(CancelImportBatch::class)->handle($login, $batch->code)->status)->toBe(ImportBatchStatus::Cancelled);
});

it('★ B.9 step 5: photos named in the row go through the media pipeline (PENDING); an unusable one is a row WARNING, the profile stays', function (): void {
    $login = bureauLogin(BureauRole::DataEntry);
    $image = UploadedFile::fake()->image('a.jpg', 600, 600);   // kept: its temp file lives as long as the object
    $good = (string) file_get_contents($image->getRealPath());
    $batch = pipelineReady($login, [importRow(['photo_files' => 'a.jpg; b.jpg'])], importZip(['a.jpg' => $good, 'b.jpg' => 'not really a photo']));

    $batch = importNow($login, $batch);
    $profile = Profile::query()->where('import_batch_id', $batch->id)->firstOrFail();
    $photos = Media::query()->where('model_id', $profile->id)->where('collection_name', Profile::PHOTOS)->get();

    expect($photos)->toHaveCount(1)
        ->and($photos[0]->moderation_status)->toBe(PhotoStatus::Pending)
        ->and(collect(rowAt($batch, 2)->messages)->pluck('text')->implode(' '))->toContain('b.jpg');
});

it('★ R-M13-32 auto-pause: > 40 % of a batch’s decided profiles rejected (with at least 5 decided) pauses imports; single creation still works (R-A07-9)', function (): void {
    Event::fake([ImportsPaused::class]);
    $login = bureauLogin(BureauRole::Owner);
    $broker = staffOf($login)->broker;
    $batch = BrokerImportBatch::factory()->create(['broker_id' => $broker->id, 'uploaded_by_user_id' => $login->id, 'status' => ImportBatchStatus::Completed]);
    $profiles = Profile::factory()->count(6)->brokerManaged($broker)->create(['import_batch_id' => $batch->id]);
    $decide = function (int $i, ModerationStatus $status) use ($profiles): void {
        ModerationItem::factory()->create(['type' => ModerationItemType::ProfileNew, 'profile_id' => $profiles[$i]->id, 'status' => $status, 'decided_at' => now()]);
        app(AnnounceModerationDecision::class)->handle($profiles[$i]->refresh(), $status === ModerationStatus::Approved);
    };

    // 3 rejected of 4 decided (75 %) — under the minimum of 5 decided: no pause.
    $decide(0, ModerationStatus::Rejected);
    $decide(1, ModerationStatus::Rejected);
    $decide(2, ModerationStatus::Rejected);
    $decide(3, ModerationStatus::Approved);
    expect($broker->refresh()->imports_paused_at)->toBeNull();

    // 3 of 6 submitted: the 5th decision makes 3 / 5 decided, 3 / 5 submitted = 60 % > 40 %.
    $decide(4, ModerationStatus::Approved);
    expect($broker->refresh()->imports_paused_at)->not->toBeNull()
        ->and($broker->imports_paused_reason)->toBe(ImportsPauseReason::Auto)
        ->and($broker->imports_paused_batch_id)->toBe($batch->id)
        ->and([$batch->refresh()->rows_submitted, $batch->rows_approved, $batch->rows_rejected])->toBe([5, 2, 3])
        ->and($batch->approvalRate())->toBe(40)
        ->and(AuditLog::query()->where('action', 'broker.imports_paused')->exists())->toBeTrue();
    Event::assertDispatched(ImportsPaused::class, fn (ImportsPaused $e): bool => $e->brokerId === $broker->id && $e->reason === 'AUTO' && $e->batchCode === $batch->code);

    // A07 is alerted on the A02 dashboard (brokers.imports.manage).
    expect(collect(app(DashboardAlerts::class)->for([], system: false, brokers: true))->pluck('text')->implode(' '))->toContain($broker->code);

    // R-A07-9: uploads are refused, single creation still works.
    app(BureauGate::class)->forget();
    expect(fn () => app(StoreImportBatch::class)->handle($login, importXlsx([importRow()]), null))->toThrow(ImportRefused::class, 'paused');
    app(BureauGate::class)->forget();
    $single = app(App\Actions\Broker\ManagedProfiles\CreateManagedProfile::class)->handle(
        $login, new App\Data\Broker\ManagedProfileData('9876500199', true), basicData(['first_name' => 'Single']));
    expect($single->status)->toBe(ProfileStatus::Draft);
});

it('★ R-M13-32: exactly 40 % rejected does not pause (the rule is “more than”)', function (): void {
    $login = bureauLogin(BureauRole::Owner);
    $broker = staffOf($login)->broker;
    $batch = BrokerImportBatch::factory()->create(['broker_id' => $broker->id, 'uploaded_by_user_id' => $login->id, 'status' => ImportBatchStatus::Completed]);
    $profiles = Profile::factory()->count(5)->brokerManaged($broker)->create(['import_batch_id' => $batch->id]);
    foreach ([ModerationStatus::Rejected, ModerationStatus::Rejected, ModerationStatus::Approved, ModerationStatus::Approved, ModerationStatus::Approved] as $i => $status) {
        ModerationItem::factory()->create(['type' => ModerationItemType::ProfileNew, 'profile_id' => $profiles[$i]->id, 'status' => $status, 'decided_at' => now()]);
        app(AnnounceModerationDecision::class)->handle($profiles[$i]->refresh(), $status === ModerationStatus::Approved);
    }

    expect($broker->refresh()->imports_paused_at)->toBeNull()->and($batch->refresh()->rows_rejected)->toBe(2);
});

it('★ B.9 budget: 500 rows import within budget with live progress', function (): void {
    $login = bureauLogin(BureauRole::DataEntry);
    establishedBureau($login);
    $rows = importRows(500);
    $batch = pipelineReady($login, $rows);
    expect($batch->rows_valid + $batch->rows_warning)->toBe(500);

    Event::fake([App\Events\Broker\ImportProgress::class, ImportCompleted::class]);
    $started = microtime(true);
    $batch = importNow($login, $batch);
    $seconds = microtime(true) - $started;
    fwrite(STDERR, sprintf("\n[P7.7] 500 rows imported in %.1f s\n", $seconds));

    expect($batch->rows_imported)->toBe(500)
        ->and($seconds)->toBeLessThan(120.0);
    Event::assertDispatchedTimes(App\Events\Broker\ImportProgress::class, 6);   // 5 chunks + the end
});

it('★ R-M13-18 / R-M13-6: another bureau’s batch code is 404 for attest, start, cancel and submit-all — nothing changes', function (string $action): void {
    $login = bureauLogin(BureauRole::DataEntry);
    $batch = pipelineReady($login, [importRow()]);
    $stranger = bureauLogin(BureauRole::Owner);
    app(BureauGate::class)->forget();

    $call = match ($action) {
        'attest' => fn () => app(AttestBatchConsent::class)->handle($stranger, $batch->code),
        'start' => fn () => app(StartBatchImport::class)->handle($stranger, $batch->code),
        'cancel' => fn () => app(CancelImportBatch::class)->handle($stranger, $batch->code),
        'submit' => fn () => app(App\Actions\Broker\Import\SubmitImportedProfiles::class)->handle($stranger, $batch->code),
    };

    expect($call)->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect($batch->refresh()->status)->toBe(ImportBatchStatus::Ready)->and($batch->consent_attested_at)->toBeNull();
})->with(['attest', 'start', 'cancel', 'submit']);

it('A02: the auto-pause alert goes once the pause is lifted (P7.8 resume clears imports_paused_at)', function (): void {
    $broker = staffOf(bureauLogin())->broker;
    $broker->forceFill(['imports_paused_at' => now(), 'imports_paused_reason' => ImportsPauseReason::Auto])->save();
    expect(app(DashboardAlerts::class)->for([], system: false, brokers: true))->toHaveCount(1)
        ->and(app(DashboardAlerts::class)->for([], system: false, brokers: false))->toBe([]);

    $broker->forceFill(['imports_paused_at' => null])->save();
    expect(app(DashboardAlerts::class)->for([], system: false, brokers: true))->toBe([]);
});
