<?php

declare(strict_types=1);

use Laravel\Dusk\Browser;

/*
| P1.5 — the profile page and My Profile at phone width (375 px device emulation).
| Helpers duskLiveMember() / demoGroomCode() live in tests/Support/dusk-member.php.
*/

beforeEach(fn () => duskMemberCleanup());
afterEach(fn () => duskMemberCleanup());

it('P1.5: the profile page and My Profile have no horizontal scroll at 375 px', function (string $page): void {
    $this->browse(function (Browser $browser) use ($page): void {
        duskLiveMember($browser);

        $browser->visit($page === 'me' ? '/me' : '/profile/'.demoGroomCode())->waitUntilMissing('#preloader', 10);

        assertNoHorizontalScroll($browser);
    });
})->with(['me', 'profile']);
