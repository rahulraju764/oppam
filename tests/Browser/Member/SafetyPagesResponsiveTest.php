<?php

declare(strict_types=1);

use App\Enums\PlanCode;
use Laravel\Dusk\Browser;

/*
| P6.2 — oppam-ui-standards responsive check for the new member pages: no sideways scrolling at
| 320 / 375 / 768 / 1024 / 1440 px on /safety, /contact, /settings/blocked, /help and the profile
| page's More menu (needs `composer dev`).
*/

beforeEach(fn () => duskChatCleanup());
afterEach(fn () => duskChatCleanup());

it('oppam-ui-standards: the safety & support pages never scroll sideways at 320–1440 px', function (): void {
    $this->browse(function (Browser $browser): void {
        duskLiveMember($browser);
        $groom = chatOnPlan(duskSecondMember(), PlanCode::Gold);

        foreach ([320, 375, 768, 1024, 1440] as $width) {
            $browser->resize($width, 900);
            foreach (['/safety', '/contact', '/settings/blocked', '/help', '/profile/'.$groom->profile->code] as $path) {
                $browser->visit($path)->waitFor('main');
                assertNoHorizontalScroll($browser);
            }
            // The More menu opened on the profile page stays inside the viewport.
            $browser->click('.safety-menu__toggle')->waitFor('.safety-menu__item--danger');
            assertNoHorizontalScroll($browser);
            $right = (float) $browser->script("return document.querySelector('.safety-menu__list').getBoundingClientRect().right;")[0];
            expect($right)->toBeLessThanOrEqual((float) $width);
        }
    });
});
