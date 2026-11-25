<?php

declare(strict_types=1);

use Laravel\Dusk\Browser;

/*
| P9.3a — Font Awesome 6 in the admin panel (`composer dev` running): the sidebar (every nav icon) and
| the A02 dashboard draw every visible icon with an FA6 font.
*/

beforeEach(fn () => duskAdminCleanup());
afterEach(fn () => duskAdminCleanup());

it('P9.3a: the admin sidebar and dashboard icons are drawn by Font Awesome 6', function (): void {
    $this->browse(function (Browser $browser): void {
        duskSignInAdmin($browser);

        foreach (['/', '/system/health'] as $page) {
            $browser->visit(adminDuskUrl($page))->waitFor('main#main', 15)->pause(500);

            expect(brokenFontAwesomeIcons($browser))->toBe([], "icons not drawn by Font Awesome 6 on admin $page");
        }
    });
});
