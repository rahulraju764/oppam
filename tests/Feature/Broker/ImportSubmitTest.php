<?php

declare(strict_types=1);

use App\Actions\Broker\Import\SubmitImportedProfiles;
use App\Domain\Broker\BureauGate;
use App\Domain\Moderation\ModerationFlags;
use App\Enums\BureauRole;
use App\Enums\Flag;
use App\Enums\ModerationItemType;
use App\Enums\ProfileScope;
use App\Enums\ProfileStatus;
use App\Models\BrokerStaff;
use App\Models\ModerationItem;
use App\Models\Profile;
use App\Models\User;
use Database\Seeders\PlansSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
| P7.7 — §11A B.9 step 6 "Submit all complete profiles → PENDING_REVIEW → A04 queue, flagged
| 'Added by broker BRK1042 · batch B-0042 · entered by <staff>'": only complete DRAFTs, through the
| per-profile path, never auto-approved (R-M13-14 / R-M13-30), inside the caller's scope.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    flagOn(Flag::BrokerBulkImport);
    fakeMediaDisks();
    Queue::fake();
    Bus::fake([PerformConversionsJob::class]);
});

/** Finish an imported draft the way the bureau would: the remaining sections and a photo. */
function completeImported(User $login, Profile $profile): Profile
{
    app(App\Actions\Profile\SaveCareerDetails::class)->handle($login, $profile->refresh(), careerData());
    app(App\Actions\Profile\SaveFamilyDetails::class)->handle($login, $profile->refresh(), familyData());
    app(App\Actions\Profile\SavePartnerPreferences::class)->handle($login, $profile->refresh(), preferenceData());
    app(App\Actions\Profile\SaveContactDetails::class)->handle($login, $profile->refresh(), contactData());
    app(App\Actions\Profile\SaveAboutDetails::class)->handle($login, $profile->refresh(), aboutData());
    app(App\Actions\Profile\Photos\UploadProfilePhoto::class)->handle($login, $profile->refresh(), UploadedFile::fake()->image('photo.jpg', 600, 800));

    return $profile->refresh();
}

it('★ B.9 step 6 / R-M13-14: complete imported drafts go to A04 with the batch provenance; incomplete ones are counted', function (): void {
    $login = bureauLogin(BureauRole::DataEntry);
    $batch = importNow($login, pipelineReady($login, [importRow(), importRow(['first_name' => 'Meera'], '9876500102')]));
    $done = Profile::query()->where('import_batch_id', $batch->id)->where('first_name', 'Anjali')->firstOrFail();
    $draft = Profile::query()->where('import_batch_id', $batch->id)->where('first_name', 'Meera')->firstOrFail();
    completeImported($login, $done);

    $result = app(SubmitImportedProfiles::class)->handle($login, $batch->code);

    expect([$result->submitted, $result->incomplete])->toBe([1, 1])
        ->and($done->refresh()->status)->toBe(ProfileStatus::PendingReview)
        ->and($draft->refresh()->status)->toBe(ProfileStatus::Draft)
        ->and($batch->refresh()->rows_submitted)->toBe(1);

    $item = ModerationItem::query()->where('profile_id', $done->id)->where('type', ModerationItemType::ProfileNew)->firstOrFail();
    $flags = collect(app(ModerationFlags::class)->forProfile($done, []))->pluck('message', 'code');
    $staff = staffOf($login)->load(['user', 'broker']);
    expect($item->status->value)->toBe('OPEN')
        ->and($flags['broker_managed'])->toBe('Added by broker '.$staff->broker->code.' · batch '.$batch->code.' · entered by '.$staff->displayName().'.');
});

it('R-M13-20: an assigned-only login submits only its own clients from the batch; a Telecaller is 403', function (): void {
    $login = bureauLogin(BureauRole::DataEntry);
    $batch = importNow($login, pipelineReady($login, [importRow()]));
    $profile = completeImported($login, Profile::query()->where('import_batch_id', $batch->id)->firstOrFail());

    $assigned = bureauLogin(BureauRole::DataEntry, broker: staffOf($login)->broker);
    BrokerStaff::query()->where('user_id', $assigned->id)->update(['profile_scope' => ProfileScope::Assigned->value]);
    app(BureauGate::class)->forget();
    expect(app(SubmitImportedProfiles::class)->handle($assigned, $batch->code)->submitted)->toBe(0)
        ->and($profile->refresh()->status)->toBe(ProfileStatus::Draft);

    $telecaller = bureauLogin(BureauRole::Telecaller, broker: staffOf($login)->broker);
    app(BureauGate::class)->forget();
    expect(fn () => app(SubmitImportedProfiles::class)->handle($telecaller, $batch->code))
        ->toThrow(fn (HttpException $e) => expect($e->getStatusCode())->toBe(403));
});
