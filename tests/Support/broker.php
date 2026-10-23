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
