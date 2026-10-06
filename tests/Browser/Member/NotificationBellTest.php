<?php

declare(strict_types=1);

use App\Notifications\Member\DailyMatchesReady;
use Laravel\Dusk\Browser;

/*
| P3.2 — M08 acceptance in real browsers over Reverb (needs `composer dev`): a new notification
| raises the bell count live and shows a toast; reading in one tab lowers the count in another tab
| of the same member.
*/

beforeEach(fn () => duskMemberCleanup());
afterEach(fn () => duskMemberCleanup());

it('P3.2: the bell rises live with a toast, and "mark all as read" in one tab clears it in the other', function (): void {
    $this->browse(function (Browser $first, Browser $second): void {
        $member = duskLiveMember($first);
        $userChannelReady = "return window.Alpine?.store('realtime')?.userChannel === true;";
        $count = fn (Browser $b): string => (string) $b->script("return document.querySelector('.nav-bell .nav-bell-count')?.textContent?.trim() ?? '0';")[0];

        $first->resize(1280, 800)->visit('/me')
            ->waitUsing(15, 100, fn (): bool => (bool) $first->script($userChannelReady)[0]);

        $second->resize(1280, 800)->visit('/login')
            ->type('#loginId', '9999900001')
            ->type('#loginPassword', 'kerala2026')
            ->click('.login-form button[type="submit"]')
            ->waitUsing(15, 200, fn (): bool => ! str_contains((string) $second->driver->getCurrentURL(), '/login'))
            ->visit('/me')
            ->waitUsing(15, 100, fn (): bool => (bool) $second->script($userChannelReady)[0]);

        $member->notify(new DailyMatchesReady(3));   // the queue worker delivers database + live

        $first->waitUsing(20, 200, fn (): bool => $count($first) === '1')
            ->waitForText('Your 3 new matches are ready');   // the toast
        $second->waitUsing(20, 200, fn (): bool => $count($second) === '1');

        $first->click('.nav-bell')
            ->waitForText('Mark all as read')
            ->press('Mark all as read')
            ->waitUsing(10, 200, fn (): bool => $count($first) === '0');

        $second->waitUsing(15, 200, fn (): bool => $count($second) === '0');   // decremented across tabs
    });
});
