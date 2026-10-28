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
| P7.1 – P7.6 — the broker portal at phone width (375 px, device emulation; B.11 "fully responsive"):
| /broker/apply, the dashboard of a Data-entry login (no device challenge), materials, the device
| check an Owner / Manager meets on a new device and, once trusted, the referral list — none scroll
| sideways, the main actions are reachable.
*/

const DUSK_BROKER_PHONE = '+919999900031';

/**
 * P7.4: threads opened by the Client Inbox (accepting creates the pair's conversation) go before
 * their profiles.
 *
 * @param  list<string>  $profileIds
 */
function duskConversationsCleanup(array $profileIds): void
{
    $threads = App\Models\ConversationParticipant::query()->whereIn('profile_id', $profileIds)->pluck('conversation_id')->unique()->all();
    App\Models\Conversation::query()->whereKey($threads)->update(['last_message_id' => null]);
    App\Models\Message::query()->whereIn('conversation_id', $threads)->delete();
    App\Models\ConversationParticipant::query()->whereIn('conversation_id', $threads)->delete();
    App\Models\Conversation::query()->whereKey($threads)->delete();
}

/** P7.4: the claiming client and the suitor (both member numbers in the fake 99999 block), threads first. */
function duskClaimCleanup(): void
{
    $users = User::withTrashed()->whereIn('phone', [DUSK_MEMBER_PHONE, '+919999900042'])->pluck('id');
    duskConversationsCleanup(App\Models\Profile::withTrashed()->whereIn('user_id', $users)->pluck('id')->all());
    duskMemberCleanup();
    duskMemberCleanup('+919999900042');
}

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
    duskConversationsCleanup($profiles->modelKeys());
    App\Models\Profile::withTrashed()->whereKey($profiles->modelKeys())->forceDelete();
    // P7.6: import batches (rows cascade) and their private files.
    foreach (App\Models\BrokerImportBatch::query()->whereIn('broker_id', $brokers)->pluck('id') as $batchId) {
        Illuminate\Support\Facades\Storage::disk((string) config('oppam.media.private_disk'))->deleteDirectory('imports/'.$batchId);
    }
    App\Models\BrokerImportBatch::query()->whereIn('broker_id', $brokers)->delete();
    // P7.5: the bureau's other logins (the Owner who invited the Dusk number) and its invitations go too.
    $users = $users->merge(BrokerStaff::query()->whereIn('broker_id', $brokers)->pluck('user_id'))->unique();
    App\Models\BrokerStaffInvitation::query()->whereIn('broker_id', $brokers)->orWhere('phone', DUSK_BROKER_PHONE)->delete();
    App\Models\TrustedDevice::query()->whereIn('user_id', $users)->delete();
    BrokerStaff::query()->whereIn('broker_id', $brokers)->delete();
    Broker::query()->whereIn('id', $brokers)->delete();
    User::withTrashed()->whereIn('id', $users)->forceDelete();
    FeatureFlag::query()->where('key', Flag::BrokerSelfApply->value)->delete();
    app(App\Services\Settings\FeatureFlags::class)->flush();

    // The OTP send limits of the throwaway number (P7.5 accepts an invitation by OTP), as duskMemberCleanup does.
    $number = sha1(DUSK_BROKER_PHONE);
    foreach (['otp:gap:', 'otp:phone15:', 'otp:phoneday:'] as $prefix) {
        Illuminate\Support\Facades\RateLimiter::clear($prefix.$number);
    }
    foreach (['127.0.0.1', '::1'] as $ip) {
        Illuminate\Support\Facades\RateLimiter::clear('otp:ipday:'.$ip);
    }
}

/** Each journey starts signed out: the browser is shared between tests (an old "intended" URL would leak in). */
function duskFreshSession(Browser $browser): void
{
    $browser->visit('/')->driver->manage()->deleteAllCookies();
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
        duskFreshSession($browser);
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
        $browser->waitUntilMissingText('Our team is reviewing this profile', 15)->assertSee('Active');   // Data entry: no Deactivate (B.2)
        fwrite(STDERR, sprintf("\n[P7.3] approval → live status on the bureau page in %.1f s\n", microtime(true) - $started));
    });

    duskBrokerCleanup();   // the decided A04 item points at the Dusk admin: bureau rows first
    duskAdminCleanup();
});

it('P7.4: a Telecaller answers the Client Inbox at 375px; the client then registers with the stored mobile and claims the profile', function (): void {
    duskClaimCleanup();

    $this->browse(function (Browser $bureau, Browser $client): void {
        $staff = bureauLogin(BureauRole::Telecaller);
        $staff->forceFill(['phone' => DUSK_BROKER_PHONE, 'password' => Hash::make(TEST_MEMBER_PASSWORD)])->save();
        $profile = managedProfile($staff->brokerStaff->broker, ['client_phone' => DUSK_MEMBER_PHONE, 'first_name' => 'Dusk', 'last_name' => 'Client'], $staff->brokerStaff);   // Telecallers: assigned clients only (B.2)
        $suitor = groom('+919999900042');
        app(App\Actions\Engagement\SendInterest::class)->handle($suitor, $profile, null);

        duskFreshSession($bureau);
        duskFreshSession($client);
        $bureau->visit('/login')
            ->type('#loginId', '9999900031')
            ->type('#loginPassword', TEST_MEMBER_PASSWORD)
            ->click('.login-form button[type="submit"]')
            ->waitForLocation('/broker');

        // Client Inbox: the waiting interest, accepted on the client's behalf.
        $bureau->visit('/broker/profiles/'.$profile->code.'/inbox')->waitForText('Accept for client')
            ->assertSee('Chat stays closed until the client joins');
        assertNoHorizontalScroll($bureau);
        $bureau->press('Accept for client')
            ->waitUsing(10, 100, fn (): bool => App\Models\Interest::query()->where('to_profile_id', $profile->id)->value('status') === App\Enums\InterestStatus::Accepted)
            ->waitForText('Nothing here');   // the waiting list is empty again
        assertNoHorizontalScroll($bureau);

        // The client registers with the same mobile + OTP: "Is this you?" at phone width.
        $masked = '+91 99•••••001';
        $previous = duskLatestOtp($masked);
        $client->visit('/register')
            ->waitUntilMissing('#preloader', 10)
            ->select('#profileFor', 'SELF')
            ->radio('gender', 'FEMALE')
            ->type('#regName', 'Dusk Client')
            ->type('#regMobile', '9999900001')
            ->type('#regPassword', TEST_MEMBER_PASSWORD)
            ->check('#regTerms')
            ->click('.login-form button[type="submit"]')
            ->waitForLocation('/verify-otp')
            ->waitUsing(10, 100, fn (): bool => duskLatestOtp($masked) !== null && duskLatestOtp($masked) !== $previous)
            ->type('#otpCode', (string) duskLatestOtp($masked))
            ->click('.login-form button[type="submit"]')
            ->waitForLocation('/register/claim')
            ->waitForText('Is this you?')
            ->assertSee('Dusk C.')
            ->assertSee($profile->code);
        assertNoHorizontalScroll($client);

        $client->click('.claim-card button.login-btn')->waitUntilMissingText('Is this you?', 15);

        $profile->refresh();
        expect($profile->owner_type)->toBe(App\Enums\ProfileOwnerType::Self)
            ->and($profile->user_id)->toBe(User::query()->where('phone', DUSK_MEMBER_PHONE)->value('id'))
            ->and($profile->managed_by_broker_id)->toBeNull();

        // The bureau's access is gone at once.
        $bureau->visit('/broker/profiles/'.$profile->code)->waitForText('We couldn’t find that page');
    });

    duskClaimCleanup();
});

it('P7.5: an invited Telecaller joins by link + OTP at 375px; deactivating them signs their open tab out live', function (): void {
    $this->withoutDefer();   // the invitation SMS is deferred; run it now, in this process (fakeSms)
    $flag = FeatureFlag::query()->where('key', Flag::BrokerStaff->value)->first();
    $wasOn = $flag?->is_enabled;
    ($flag ?? new FeatureFlag)->forceFill(['key' => Flag::BrokerStaff->value, 'is_enabled' => true])->save();
    app(App\Services\Settings\FeatureFlags::class)->flush();

    try {
        $this->browse(function (Browser $browser): void {
            duskFreshSession($browser);
            $owner = bureauLogin(BureauRole::Owner);
            $sms = fakeSms();   // the INVITATION is sent from this process; the OTP comes from the dev server's SMS log
            app(App\Actions\Broker\Staff\InviteStaff::class)->handle($owner, new App\Data\Broker\StaffInviteData('Dusk Telecaller', '9999900031', 'TELECALLER', 'ASSIGNED'));
            preg_match('#invite/([A-Za-z0-9]{64})#', (string) $sms->notices[0]['variables'][1], $match);

            $masked = '+91 99•••••031';
            $previous = duskLatestOtp($masked);
            $browser->visit('/broker/staff/invite/'.$match[1])->waitForText('Dusk Telecaller');
            assertNoHorizontalScroll($browser);
            $browser->type('#invitePassword', TEST_MEMBER_PASSWORD)
                ->type('#invitePasswordConfirm', TEST_MEMBER_PASSWORD)
                ->click('.login-form button[type="submit"]')
                ->waitForText('Enter the code')
                ->waitUsing(10, 100, fn (): bool => duskLatestOtp($masked) !== null && duskLatestOtp($masked) !== $previous)
                ->type('#inviteCode', (string) duskLatestOtp($masked))
                ->click('.login-form button[type="submit"]')
                ->waitForLocation('/broker')
                ->waitForText('Managed profiles');
            assertNoHorizontalScroll($browser);

            // The new login is live on its own channel; the Owner deactivates it.
            $login = User::query()->where('phone', DUSK_BROKER_PHONE)->firstOrFail();
            $browser->waitUsing(20, 100, fn (): bool => (bool) $browser->script(
                "return window.Echo?.connector?.channels?.['private-App.Models.User.{$login->id}']?.subscription?.subscribed === true;")[0]);

            $started = microtime(true);
            app(App\Actions\Broker\Staff\DeactivateStaff::class)->handle($owner, (string) $login->brokerStaff?->code);
            $browser->waitUsing(10, 50, fn (): bool => str_contains((string) $browser->driver->getCurrentURL(), '/login'));
            $elapsed = microtime(true) - $started;
            fwrite(STDERR, sprintf("\n[P7.5] deactivation → staff tab on /login in %.2f s\n", $elapsed));

            expect($elapsed)->toBeLessThan(2.0);   // B.21 "logs them out of every tab within 2 s"
            $browser->visit('/broker')->waitForLocation('/login');
        });
    } finally {
        $wasOn === null   // the row didn't exist before this test: remove it again
            ? FeatureFlag::query()->where('key', Flag::BrokerStaff->value)->delete()
            : FeatureFlag::query()->where('key', Flag::BrokerStaff->value)->update(['is_enabled' => $wasOn]);
        app(App\Services\Settings\FeatureFlags::class)->flush();
    }
});

it('P7.5: the Owner’s staff, staff detail and activity pages fit 375px', function (): void {
    $flag = FeatureFlag::query()->where('key', Flag::BrokerStaff->value)->first();
    $wasOn = $flag?->is_enabled;
    ($flag ?? new FeatureFlag)->forceFill(['key' => Flag::BrokerStaff->value, 'is_enabled' => true])->save();
    app(App\Services\Settings\FeatureFlags::class)->flush();

    try {
        $this->browse(function (Browser $browser): void {
            $owner = bureauLogin(BureauRole::Owner);
            $owner->forceFill(['phone' => DUSK_BROKER_PHONE, 'password' => Hash::make(TEST_MEMBER_PASSWORD)])->save();
            $telecaller = bureauLogin(BureauRole::Telecaller, broker: $owner->brokerStaff?->broker);
            app(App\Services\Audit\AuditLogger::class)->record('broker.profile_created', null, after: ['x' => 1], actor: $owner, subjectLabel: 'OPM10001');

            duskFreshSession($browser);
            $browser->visit('/login')
                ->type('#loginId', '9999900031')
                ->type('#loginPassword', TEST_MEMBER_PASSWORD)
                ->click('.login-form button[type="submit"]')
                ->waitForLocation('/broker/verify-device');
            app(TrustedDevices::class)->trust($owner, hash('sha256', (string) $browser->cookie((string) config('oppam.auth.device_cookie'))));

            $browser->visit('/broker/staff')->waitForText('Invite a staff member')->assertSee('1 of 5 seats used');
            assertNoHorizontalScroll($browser);
            $browser->visit('/broker/staff/'.$telecaller->brokerStaff?->code)->waitForText('Permissions')->assertSee('Deactivate');
            assertNoHorizontalScroll($browser);
            $browser->visit('/broker/activity')->waitForText('Added a profile');
            assertNoHorizontalScroll($browser);
        });
    } finally {
        $wasOn === null   // the row didn't exist before this test: remove it again
            ? FeatureFlag::query()->where('key', Flag::BrokerStaff->value)->delete()
            : FeatureFlag::query()->where('key', Flag::BrokerStaff->value)->update(['is_enabled' => $wasOn]);
        app(App\Services\Settings\FeatureFlags::class)->flush();
    }
});

it('P7.6: a Data-entry login uploads a 500-row sheet at 375px; validation progresses live to READY; errors filter', function (): void {
    $flag = FeatureFlag::query()->where('key', Flag::BrokerBulkImport->value)->first();
    $wasOn = $flag?->is_enabled;
    ($flag ?? new FeatureFlag)->forceFill(['key' => Flag::BrokerBulkImport->value, 'is_enabled' => true])->save();
    app(App\Services\Settings\FeatureFlags::class)->flush();

    // Mobiles in the throwaway 99998 block, so no demo account on the dev database collides with them.
    $rows = array_map(fn (array $r, int $i): array => ['client_mobile' => '99998'.str_pad((string) $i, 5, '0', STR_PAD_LEFT)] + $r, importRows(500), range(1, 500));
    $rows[9]['height'] = 'tall';                       // sheet row 11: an ERROR
    $rows[19]['date_of_birth'] = '12-05-98';           // sheet row 21: an ERROR
    $sheet = importXlsx($rows);

    try {
        $this->browse(function (Browser $browser) use ($sheet): void {
            $staff = bureauLogin(BureauRole::DataEntry);
            $staff->forceFill(['phone' => DUSK_BROKER_PHONE, 'password' => Hash::make(TEST_MEMBER_PASSWORD)])->save();
            duskFreshSession($browser);
            $browser->visit('/login')
                ->type('#loginId', '9999900031')
                ->type('#loginPassword', TEST_MEMBER_PASSWORD)
                ->click('.login-form button[type="submit"]')
                ->waitForLocation('/broker');

            $browser->visit('/broker/import')->waitForText('1. Download the template');
            assertNoHorizontalScroll($browser);

            $started = microtime(true);
            $browser->attach('#importSheet', (string) $sheet->getRealPath())
                // The temporary upload has finished once the component holds the file (the button is enabled again).
                ->waitUsing(20, 100, fn (): bool => (bool) $browser->script(
                    "const c = window.Livewire?.all?.().find(c => c.name === 'broker.import.upload'); return !!(c && c.\$wire.sheet) && !document.querySelector('.import-upload button[type=submit]').disabled;")[0])
                ->click('.import-upload button[type="submit"]')
                ->waitUsing(20, 100, fn (): bool => str_contains((string) $browser->driver->getCurrentURL(), '/broker/import/B-'));
            assertNoHorizontalScroll($browser);

            // The page is subscribed; validation runs on the queue and the bar moves to READY live.
            $brokerId = $staff->brokerStaff?->broker_id;
            $browser->waitUntilMissingText('Checking rows', 60)->waitForText('Download rows to fix', 30);
            fwrite(STDERR, sprintf("\n[P7.6] upload → READY on the page in %.1f s\n", microtime(true) - $started));
            assertNoHorizontalScroll($browser);

            $batch = App\Models\BrokerImportBatch::query()->where('broker_id', $brokerId)->latest()->firstOrFail();
            expect($batch->status)->toBe(App\Enums\ImportBatchStatus::Ready)
                ->and($batch->rows_total)->toBe(500)
                ->and($batch->rows_error)->toBe(2);

            $browser->press('Errors')->waitUntilMissingText('Row 12')->assertSee('Row 11')->assertSee('Row 21');
            assertNoHorizontalScroll($browser);
        });
    } finally {
        $wasOn === null
            ? FeatureFlag::query()->where('key', Flag::BrokerBulkImport->value)->delete()
            : FeatureFlag::query()->where('key', Flag::BrokerBulkImport->value)->update(['is_enabled' => $wasOn]);
        app(App\Services\Settings\FeatureFlags::class)->flush();
    }
});
