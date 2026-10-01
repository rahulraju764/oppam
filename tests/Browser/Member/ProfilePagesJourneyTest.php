<?php

declare(strict_types=1);

use Laravel\Dusk\Browser;

/*
| P1.5 — profile pages in a real browser (dev server + demo data): a live member opens an
| opposite-gender demo profile, switches to the Partner Preference tab, sees the masked contact
| block; then their own /me with completeness and preview. Throwaway member cleaned around each test.
*/

beforeEach(fn () => duskMemberCleanup());
afterEach(fn () => duskMemberCleanup());

it('P1.5: opens a demo profile, switches tabs, and sees the contact block locked for a free member', function (): void {
    $this->browse(function (Browser $browser): void {
        duskLiveMember($browser);
        $code = demoGroomCode();

        $browser->visit('/profile/'.$code)
            ->waitUntilMissing('#preloader', 10)
            ->assertSee($code)
            ->assertSee('Contact Details')
            ->assertSee('Upgrade your plan to view contact details.')
            ->click('#preference-tab')
            ->waitFor('#preference.show')
            ->assertVisible('#preference');
    });
});

it('P1.5: My Profile shows completeness and links to a preview of the own profile', function (): void {
    $this->browse(function (Browser $browser): void {
        $user = duskLiveMember($browser);

        $browser->visit('/me')
            ->waitUntilMissing('#preloader', 10)
            ->assertSee('Profile Completeness')
            ->assertSee('Add a photo (+15%)')
            ->clickLink('Preview')
            ->waitForText('This is how other members see your profile.')
            ->assertSee($user->profile->first_name);
    });
});
