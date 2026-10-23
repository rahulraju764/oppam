<?php

declare(strict_types=1);

use App\Enums\BureauRole;
use App\Enums\Flag;
use App\Models\Broker;
use App\Models\BrokerStaff;
use App\Models\FeatureFlag;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;

/*
| P7.1 — the broker portal at phone width (375 px, device emulation; B.11 "fully responsive"):
| /broker/apply, the dashboard of a Data-entry login (no device challenge), and the device check an
| Owner meets on a new device — none scroll sideways, the main actions are reachable.
*/

const DUSK_BROKER_PHONE = '+919999900031';

function duskBrokerCleanup(): void
{
    $users = User::withTrashed()->where('phone', DUSK_BROKER_PHONE)->pluck('id');
    $brokers = BrokerStaff::query()->whereIn('user_id', $users)->pluck('broker_id');
    BrokerStaff::query()->whereIn('broker_id', $brokers)->delete();
    Broker::query()->whereIn('id', $brokers)->delete();
    User::withTrashed()->whereIn('id', $users)->forceDelete();
    FeatureFlag::query()->where('key', Flag::BrokerSelfApply->value)->delete();
    app(App\Services\Settings\FeatureFlags::class)->flush();
}

beforeEach(fn () => duskBrokerCleanup());
afterEach(fn () => duskBrokerCleanup());

it('P7.1: apply, dashboard and device check work at 375px without sideways scrolling', function (): void {
    $this->browse(function (Browser $browser): void {
        FeatureFlag::factory()->create(['key' => Flag::BrokerSelfApply->value, 'is_enabled' => true]);
        app(App\Services\Settings\FeatureFlags::class)->flush();

        $browser->visit('/broker/apply')->waitForText('Apply as a marriage bureau')->assertVisible('@broker-apply');
        assertNoHorizontalScroll($browser);

        $staff = bureauLogin(BureauRole::DataEntry);
        $staff->forceFill(['phone' => DUSK_BROKER_PHONE, 'password' => Hash::make(TEST_MEMBER_PASSWORD)])->save();
        $browser->visit('/login')
            ->type('#loginId', '9999900031')
            ->type('#loginPassword', TEST_MEMBER_PASSWORD)
            ->click('.login-form button[type="submit"]')
            ->waitForLocation('/broker')
            ->waitForText('Managed profiles')
            ->assertSee('Add a profile');
        assertNoHorizontalScroll($browser);

        // The same login promoted to Manager: the next page asks to confirm the device.
        $staff->brokerStaff->forceFill(['role' => BureauRole::Manager])->save();
        $browser->visit('/broker')->waitForText('Confirm this device')->assertVisible('@device-send');
        assertNoHorizontalScroll($browser);
    });
});
