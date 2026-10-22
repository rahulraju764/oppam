<?php

declare(strict_types=1);

use Laravel\Dusk\Browser;

/*
| P6.1 — /verify at phone width (375 px, device emulation): the steps and the upload form never
| scroll sideways and the submit button is reachable; the waiting state too.
*/

beforeEach(function (): void {
    duskChatCleanup();
});

afterEach(function (): void {
    duskChatCleanup();
});

it('P6.1: /verify works at 375px without sideways scrolling', function (): void {
    $this->browse(function (Browser $browser): void {
        $groom = duskSecondMember();

        $browser->visit('/login')
            ->type('#loginId', 'dusk.groom@example.com')
            ->type('#loginPassword', TEST_MEMBER_PASSWORD)
            ->click('.login-form button[type="submit"]')
            ->waitForLocation('/dashboard')
            ->visit('/verify')
            ->waitForText('Upload your ID')
            ->assertVisible('@verify-submit');
        assertNoHorizontalScroll($browser);

        submitId($groom);
        $browser->visit('/verify')->waitForText('Your document is being checked');
        assertNoHorizontalScroll($browser);
    });
});
