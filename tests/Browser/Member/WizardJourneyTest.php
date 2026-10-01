<?php

declare(strict_types=1);

use App\Models\Masters\Caste;
use App\Models\Masters\MotherTongue;
use App\Models\Masters\Religion;
use Laravel\Dusk\Browser;

/*
| P1.2 — the profile wizard in a real browser (dev server): register → code → step 1 with the
| template's option buttons and the live religion → caste list → Continue → step 2; a reload
| restores what was saved. Throwaway number cleaned around each test (tests/Support/dusk-member.php).
*/

beforeEach(fn () => duskMemberCleanup());
afterEach(fn () => duskMemberCleanup());

it('fills step 1 with live caste list, continues to step 2 and restores after a reload', function (): void {
    $hindu = Religion::query()->where('code', 'HINDU')->firstOrFail();
    $caste = Caste::query()->where('religion_id', $hindu->id)->where('is_active', true)->orderBy('sort_order')->firstOrFail();
    $malayalam = MotherTongue::query()->where('code', 'MALAYALAM')->firstOrFail();

    $this->browse(function (Browser $browser) use ($hindu, $caste, $malayalam): void {
        duskRegisterAndVerify($browser);

        $browser->assertInputValue('#basic-first_name', 'Dusk')
            ->type('#basic-last_name', 'Bride')
            ->script("const el=document.getElementById('basic-dob'); el.value='1998-05-12'; el.dispatchEvent(new Event('input',{bubbles:true}));");

        $browser->select('#basic-height_cm', '163')
            ->click('#basic-marital_status-label + .option-btns button[wire\\:key="basic-marital_status-NEVER_MARRIED"]')
            ->click('#basic-religion_id-label + .option-btns button[wire\\:key="basic-religion_id-'.$hindu->id.'"]')
            ->waitFor('#basic-caste_id option[value="'.$caste->id.'"]')     // caste list arrived without a reload
            ->select('#basic-caste_id', (string) $caste->id)
            ->select('#basic-mother_tongue_id', (string) $malayalam->id)
            ->click('.register-right button[type="submit"]')
            ->waitForLocation('/onboarding/2')
            ->assertSourceHas('Highest Education');

        // Back to step 1 after a full reload: everything is still there.
        $browser->visit('/onboarding/1')
            ->waitUntilMissing('#preloader', 10)
            ->assertInputValue('#basic-last_name', 'Bride')
            ->assertSelected('#basic-caste_id', (string) $caste->id)
            ->assertAttribute('button[wire\\:key="basic-religion_id-'.$hindu->id.'"]', 'aria-pressed', 'true');
    });
});

it('shows errors under the fields when Continue is pressed with missing details', function (): void {
    $this->browse(function (Browser $browser): void {
        duskRegisterAndVerify($browser);

        $browser->click('.register-right button[type="submit"]')
            // Registration already saved the name; what is still missing is flagged in plain words.
            ->waitFor('#basic-dob-error')
            ->assertSeeIn('#basic-dob-error', 'date of birth')
            ->assertPresent('#basic-height_cm-error')
            ->assertPathIs('/onboarding/1');
    });
});

it('P1.3: completes steps 4–6; without a photo the submit asks for one', function (): void {
    $this->browse(function (Browser $browser): void {
        duskRegisterAndVerify($browser);
        duskCompleteFirstSteps();

        // Step 4: the age range and religion are suggested; Continue saves them.
        $browser->visit('/onboarding/4')
            ->waitUntilMissing('#preloader', 10)
            ->assertSourceHas('Religious Preference')
            ->assertAttribute('button[wire\:key^="preference-religion_ids-"].active', 'aria-pressed', 'true')
            ->click('.register-right button[type="submit"]')
            ->waitForLocation('/onboarding/5');

        // Step 5: the verified mobile is read-only; email and city are needed.
        $browser->waitUntilMissing('#preloader', 10)
            ->assertAttribute('#contact-mobile', 'readonly', 'true')
            ->type('#contact-contact_email', 'dusk.family@example.com')
            ->type('#contact-city', 'Kochi')
            ->click('.register-right button[type="submit"]')
            ->waitForLocation('/onboarding/6');

        // Step 6: a too-short about-me is flagged; a proper one submits.
        $browser->waitUntilMissing('#preloader', 10)
            ->type('#about-about', 'Too short')
            ->click('.register-right button[type="submit"]')
            ->waitFor('#about-about-error')
            ->type('#about-about', 'I teach mathematics in Kochi and love Carnatic music, books and long drives with family.')
            ->type('#about-hobbies', 'Music, Reading, Travel')
            ->click('.register-right button[type="submit"]')
            // Since P1.4 a photo is required: the submit is refused with a clear message (see the P1.4 journey).
            ->waitForText('Please add at least one photo')
            ->assertPathIs('/onboarding/6');
    });
});

it('P1.4: uploads a photo through the cropper, then submits the profile', function (): void {
    $this->browse(function (Browser $browser): void {
        duskRegisterAndVerify($browser);
        duskCompleteFirstSteps();

        $user = App\Models\User::query()->where('phone', DUSK_MEMBER_PHONE)->firstOrFail();
        $profile = $user->profile()->firstOrFail();
        app(App\Actions\Profile\SavePartnerPreferences::class)->handle($user, $profile, preferenceData());
        app(App\Actions\Profile\SaveContactDetails::class)->handle($user, $profile->refresh(), contactData());

        $browser->visit('/onboarding/6')
            ->waitUntilMissing('#preloader', 10)
            ->assertSee('No photos yet')
            ->attach('#photo-input', duskPhotoFixture())
            ->waitForText('Crop your photo')
            ->press('Use this photo')
            ->waitForText('Awaiting review', 20)
            ->assertSee('Profile photo')
            ->type('#about-about', 'I teach mathematics in Kochi and love Carnatic music, books and long drives with family.')
            ->click('.register-right button[type="submit"]')
            ->waitForLocation('/onboarding/submitted')
            ->assertSee('under review');
    });
});
