<?php

declare(strict_types=1);

use App\Enums\BrokerKycStatus;
use App\Enums\BrokerStatus;
use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Models\Broker;
use App\Models\BrokerStaff;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Support\Str;

/*
| P7.1 broker portal test helpers. Bureau logins are BROKER users with a broker_staff row; Owners
| and Managers also need a trusted device (the cookie + its TrustedDevice row) to pass broker.device.
*/

const TEST_PAN = 'ABCDE1234F';

/**
 * A bureau login. $bureau: 'verified' (ACTIVE + KYC VERIFIED), 'applied', 'kyc_pending',
 * 'suspended', 'deactivated', 'rejected'.
 *
 * @param  list<string>  $disabled  permission keys the Owner switched off
 */
function bureauLogin(BureauRole $role = BureauRole::Owner, string $bureau = 'verified', array $disabled = [], ?Broker $broker = null,
    BureauStaffStatus $status = BureauStaffStatus::Active): User
{
    $broker ??= Broker::factory()->create(match ($bureau) {
        'applied' => ['status' => BrokerStatus::Applied, 'kyc_status' => BrokerKycStatus::NotSubmitted, 'activated_at' => null],
        'kyc_pending' => ['status' => BrokerStatus::KycPending, 'kyc_status' => BrokerKycStatus::Pending, 'activated_at' => null],
        'suspended' => ['status' => BrokerStatus::Suspended],
        'deactivated' => ['status' => BrokerStatus::Deactivated],
        'rejected' => ['status' => BrokerStatus::Rejected, 'kyc_status' => BrokerKycStatus::NotSubmitted],
        default => [],
    });

    $user = User::factory()->broker()->create();
    BrokerStaff::factory()->create([
        'broker_id' => $broker->id,
        'user_id' => $user->id,
        'role' => $role,
        'status' => $status,
        'disabled_permissions' => $disabled === [] ? null : $disabled,
    ]);

    return $user->refresh();
}

/** An applicant Owner as ApplyAsBroker leaves them: APPLIED bureau, INVITED owner row. */
function applicantOwner(): User
{
    return bureauLogin(BureauRole::Owner, 'applied', status: BureauStaffStatus::Invited);
}

/** Sign a bureau login in on a device it already proved (so broker.device lets it through). */
function actingAsBureau(User $user): User
{
    $device = Str::random(40);
    TrustedDevice::factory()->create(['user_id' => $user->id, 'device_hash' => hash('sha256', $device)]);
    test()->actingAs($user, 'web')->withCookie((string) config('oppam.auth.device_cookie'), $device);

    return $user;
}

/** Send a portal page's first Livewire snapshot back to the update endpoint, as the browser would. */
function bureauLivewireUpdate(string $html): Illuminate\Testing\TestResponse
{
    preg_match('/wire:snapshot="([^"]+)"/', $html, $match);

    // withCredentials(): JSON test requests drop cookies otherwise, and broker.device reads one.
    return test()->withCredentials()->withHeaders(['X-Livewire' => 'true'])->postJson(
        'http://'.config('oppam.app_domain').app(Livewire\Mechanisms\HandleRequests\HandleRequests::class)->getUpdateUri(),
        ['components' => [['snapshot' => html_entity_decode($match[1] ?? '', ENT_QUOTES), 'updates' => [], 'calls' => []]]],
    );
}

function brokerUrl(string $path = ''): string
{
    return memberUrl('/broker'.$path);
}

/** Switch a feature flag on for this test (row + flushed cache). */
function flagOn(App\Enums\Flag $flag): void
{
    App\Models\FeatureFlag::factory()->create(['key' => $flag->value, 'is_enabled' => true]);
    app(App\Services\Settings\FeatureFlags::class)->flush();
    app()->forgetInstance(App\Services\Settings\FeatureFlags::class);   // not forgetScopedInstances(): that drops the test's withoutDefer() swap
}

// ---- P7.3 managed profiles -------------------------------------------------------------------

/** The bureau login's staff row (fresh). */
function staffOf(User $login): BrokerStaff
{
    return BrokerStaff::query()->where('user_id', $login->id)->with(['broker', 'user'])->firstOrFail();
}

/** A client profile managed by $broker (ACTIVE by default), optionally assigned to a staff row. */
function managedProfile(Broker $broker, array $attributes = [], ?BrokerStaff $assigned = null): App\Models\Profile
{
    return App\Models\Profile::factory()->brokerManaged($broker, $assigned)->female()->create($attributes)->refresh();
}

function managedData(string $phone = '9876512345', bool $consent = true): App\Data\Broker\ManagedProfileData
{
    return new App\Data\Broker\ManagedProfileData($phone, $consent);
}

/** A managed DRAFT created by $login through the real Actions, every section done incl. one photo (ready to submit). */
function managedClientReadyToSubmit(User $login, string $phone = '9876512345'): App\Models\Profile
{
    $profile = app(App\Actions\Broker\ManagedProfiles\CreateManagedProfile::class)->handle($login, managedData($phone), basicData());

    app(App\Actions\Profile\SaveCareerDetails::class)->handle($login, $profile->refresh(), careerData());
    app(App\Actions\Profile\SaveFamilyDetails::class)->handle($login, $profile->refresh(), familyData());
    app(App\Actions\Profile\SavePartnerPreferences::class)->handle($login, $profile->refresh(), preferenceData());
    app(App\Actions\Profile\SaveContactDetails::class)->handle($login, $profile->refresh(), contactData());
    app(App\Actions\Profile\SaveAboutDetails::class)->handle($login, $profile->refresh(), aboutData());

    fakeMediaDisks();
    Illuminate\Support\Facades\Bus::fake([Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob::class]);
    app(App\Actions\Profile\Photos\UploadProfilePhoto::class)->handle($login, $profile->refresh(), Illuminate\Http\UploadedFile::fake()->image('photo.jpg', 600, 800));

    return $profile->refresh();
}
