<?php

declare(strict_types=1);

use Laravel\Dusk\Browser;

/*
| P9.3b (P3.x hand-over) — presence over the real Reverb server, two browsers (`composer dev` running):
| the bride watches the groom's profile; his green dot shows while he is online, and goes as soon as he
| switches "Show when I'm online" off in /settings (presence-hidden → he leaves presence.online; no reload).
*/

beforeEach(function (): void {
    duskMemberCleanup();
    duskMemberCleanup(DUSK_SECOND_PHONE);
});

afterEach(function (): void {
    duskMemberCleanup();
    duskMemberCleanup(DUSK_SECOND_PHONE);
});

it('P3 privacy: the green dot goes for others as soon as a member switches online status off', function (): void {
    $this->browse(function (Browser $first, Browser $second): void {
        duskLiveMember($first);
        $groom = duskSecondMember();
        $dot = '[data-online-code="'.$groom->profile->code.'"]';
        $visible = fn (): bool => (bool) $first->script("const d = document.querySelector('{$dot}'); return d !== null && getComputedStyle(d).display !== 'none';")[0];

        $first->resize(1280, 800)->visit('/profile/'.$groom->profile->code)->waitForText($groom->profile->code)
            ->waitUsing(10, 100, fn (): bool => (bool) $first->script("return window.Alpine?.store('realtime')?.status === 'connected';")[0]);

        duskSignInSecond($second, '/settings');
        $second->waitFor('@privacy-online');
        $first->waitUsing(15, 200, $visible);   // he is online: the dot shows

        $second->uncheck('@privacy-online')->click('@privacy-save')->waitForText('saved', 10);

        $first->waitUsing(15, 200, fn (): bool => ! $visible());   // gone without a reload
    });
});
