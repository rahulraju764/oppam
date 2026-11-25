<?php

declare(strict_types=1);

use Laravel\Dusk\Browser;

/*
| P9.3a — Font Awesome 6 at phone width (`composer dev` running): every visible icon on the public home
| and the local styleguide pages (which show the member chrome and the UI kit) is drawn by an FA6 font.
*/

it('P9.3a: icons on the public home and the styleguide are drawn by Font Awesome 6', function (): void {
    $this->browse(function (Browser $browser): void {
        foreach (['/', '/styleguide', '/styleguide/member'] as $page) {
            $browser->visit($page)->waitUntilMissing('#preloader', 10);
            $count = $browser->script('return document.querySelectorAll(\'i[class*="fa-"]\').length;')[0];

            expect($count)->toBeGreaterThan(0, "$page shows no icons to check")
                ->and(brokenFontAwesomeIcons($browser))->toBe([], "icons not drawn by Font Awesome 6 on $page");
        }
    });
});
