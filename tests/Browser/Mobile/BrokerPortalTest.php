<?php

declare(strict_types=1);

use App\Domain\Broker\TrustedDevices;
use App\Enums\BureauRole;
use App\Enums\Flag;
use App\Models\Broker;
use App\Models\BrokerStaff;
use App\Models\FeatureFlag;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;

/*
| P7.1 / P7.2 / P7.3 — the broker portal at phone width (375 px, device emulation; B.11 "fully responsive"):
| /broker/apply, the dashboard of a Data-entry login (no device challenge), materials, the device
| check an Owner / Manager meets on a new device and, once trusted, the referral list — none scroll
| sideways, the main actions are reachable.
*/

const DUSK_BROKER_PHONE = '+919999900031';

function duskBrokerCleanup(): void
{
    $users = User::withTrashed()->where('phone', DUSK_BROKER_PHONE)->pluck('id');
    $brokers = BrokerStaff::query()->whereIn('user_id', $users)->pluck('broker_id');
    // P7.3: the bureau's managed clients (and what points at them) go before the bureau.
    $profiles = App\Models\Profile::withTrashed()->whereIn('managed_by_broker_id', $brokers)->get();
    foreach ($profiles as $profile) {
        App\Models\Media::query()->where('model_type', $profile->getMorphClass())->where('model_id', $profile->id)->get()->each(fn ($m) => $m->delete());
    }
    App\Models\ModerationItem::query()->whereIn('profile_id', $profiles->modelKeys())->delete();
    App\Models\BrokerReferral::query()->whereIn('broker_id', $brokers)->delete();
    App\Models\BrokerClientNote::query()->whereIn('broker_id', $brokers)->delete();
    App\Models\Profile::withTrashed()->whereKey($profiles->modelKeys())->forceDelete();
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
            ->waitForText('Managed profiles');
        assertNoHorizontalScroll($browser);

        // The sidebar is hidden on phones: the drawer carries the bureau menu and sign-out.
        $browser->click('.custom-toggler')
            ->waitFor('#navbarSupportedContent.open')
            ->within('#navbarSupportedContent', fn (Browser $drawer) => $drawer
                ->assertSeeLink('Add a profile')
                ->assertSee('Logout'));
        assertNoHorizontalScroll($browser);

        // P7.2 materials (any role): code, link, QR and the downloads fit a phone.
        $code = $staff->brokerStaff->broker->code;
        $browser->visit('/broker/materials')->waitForText('Referral materials')
            ->assertSee($code)->assertVisible('@copy-link')->assertVisible('@poster-download');
        assertNoHorizontalScroll($browser);

        // The same login promoted to Manager: the next page asks to confirm the device.
        $staff->brokerStaff->forceFill(['role' => BureauRole::Manager])->save();
        $browser->visit('/broker')->waitForText('Confirm this device')->assertVisible('@device-send');
        assertNoHorizontalScroll($browser);

        // Once this device is trusted, the Manager's referral list (empty state) fits a phone too.
        app(TrustedDevices::class)->trust($staff, hash('sha256', (string) $browser->cookie((string) config('oppam.auth.device_cookie'))));
        $browser->visit('/broker/referrals')->waitForText('No referrals yet')->assertSeeLink('Open materials');
        assertNoHorizontalScroll($browser);
    });
});

it('P7.3: a Data-entry login adds a client at 375px; the A04 decision reaches the open profile page live', function (): void {
    $hindu = App\Models\Masters\Religion::query()->where('code', 'HINDU')->firstOrFail();
    $caste = App\Models\Masters\Caste::query()->where('religion_id', $hindu->id)->where('is_active', true)->orderBy('sort_order')->firstOrFail();
    $malayalam = App\Models\Masters\MotherTongue::query()->where('code', 'MALAYALAM')->firstOrFail();

    $this->browse(function (Browser $browser) use ($hindu, $caste, $malayalam): void {
        $staff = bureauLogin(BureauRole::DataEntry);
        $staff->forceFill(['phone' => DUSK_BROKER_PHONE, 'password' => Hash::make(TEST_MEMBER_PASSWORD)])->save();
        $browser->visit('/login')
            ->type('#loginId', '9999900031')
            ->type('#loginPassword', TEST_MEMBER_PASSWORD)
            ->click('.login-form button[type="submit"]')
            ->waitForLocation('/broker');

        // The empty list points to "Add a profile".
        $browser->visit('/broker/profiles')->waitForText('No client profiles yet');
        assertNoHorizontalScroll($browser);

        // Create: mobile + consent + the M02 basics, then "Save draft".
        $browser->visit('/broker/profiles/create')->waitForText('I confirm the client has agreed')
            ->type('#clientPhone', '9999900041')
            ->check('#consent')
            ->type('#basic-first_name', 'Dusk')
            ->type('#basic-last_name', 'Client')
            ->click('#basic-gender-label + .option-btns button[wire\\:key="basic-gender-FEMALE"]')
            ->script("const el=document.getElementById('basic-dob'); el.value='1998-05-12'; el.dispatchEvent(new Event('input',{bubbles:true}));");
        $browser->select('#basic-height_cm', '163')
            ->click('#basic-marital_status-label + .option-btns button[wire\\:key="basic-marital_status-NEVER_MARRIED"]')
            ->click('#basic-religion_id-label + .option-btns button[wire\\:key="basic-religion_id-'.$hindu->id.'"]')
            ->waitFor('#basic-caste_id option[value="'.$caste->id.'"]')
            ->select('#basic-caste_id', (string) $caste->id)
            ->select('#basic-mother_tongue_id', (string) $malayalam->id);
        assertNoHorizontalScroll($browser);
        $browser->press('Save draft')->waitForText('Draft saved for');
        assertNoHorizontalScroll($browser);

        $profile = App\Models\Profile::query()->where('client_phone', '+919999900041')->firstOrFail();
        expect($profile->owner_type)->toBe(App\Enums\ProfileOwnerType::BrokerManaged)
            ->and($profile->created_by_user_id)->toBe($staff->id);
        $browser->assertPathIs('/broker/profiles/'.$profile->code.'/edit')->assertSee('Save & continue');

        // The rest of the sections through the same Actions, then submit.
        $login = $staff->refresh();
        app(App\Actions\Profile\SaveCareerDetails::class)->handle($login, $profile->refresh(), careerData());
        app(App\Actions\Profile\SaveFamilyDetails::class)->handle($login, $profile->refresh(), familyData());
        app(App\Actions\Profile\SavePartnerPreferences::class)->handle($login, $profile->refresh(), preferenceData());
        app(App\Actions\Profile\SaveContactDetails::class)->handle($login, $profile->refresh(), contactData());
        app(App\Actions\Profile\SaveAboutDetails::class)->handle($login, $profile->refresh(), aboutData());
        app(App\Actions\Profile\Photos\UploadProfilePhoto::class)->handle($login, $profile->refresh(), Illuminate\Http\UploadedFile::fake()->image('photo.jpg', 600, 800));
        $item = app(App\Actions\Broker\ManagedProfiles\SubmitManagedProfile::class)->handle($login, $profile->code);

        // The detail page is open (and subscribed) when A04 approves: the status flips without a reload.
        $browser->visit('/broker/profiles/'.$profile->code)->waitForText('Our team is reviewing this profile');
        assertNoHorizontalScroll($browser);
        $brokerId = $login->brokerStaff->broker_id;
        $browser->waitUsing(20, 100, fn (): bool => (bool) $browser->script(
            "return window.Echo?.connector?.channels?.['private-broker.{$brokerId}']?.subscription?.subscribed === true;")[0]);

        $started = microtime(true);
        app(App\Actions\Moderation\ApproveProfile::class)->handle(duskAdmin(), $item);
        $browser->waitUntilMissingText('Our team is reviewing this profile', 15)->assertSee('Active')->assertSee('Deactivate');
        fwrite(STDERR, sprintf("\n[P7.3] approval → live status on the bureau page in %.1f s\n", microtime(true) - $started));
    });

    duskBrokerCleanup();   // the decided A04 item points at the Dusk admin: bureau rows first
    duskAdminCleanup();
});
