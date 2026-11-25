<?php

declare(strict_types=1);

use App\Actions\Engagement\AcceptInterest;
use App\Actions\Engagement\SendInterest;
use App\Enums\PlanCode;
use Laravel\Dusk\Browser;

/*
| P9.3b — WCAG 2.1 A / AA on the signed-in member pages (axe-core; PRD §14), desktop, `composer dev` running:
| the dashboard, browse / matches / search, a profile, chat (inbox, a thread, and its report dialog open),
| notifications, every settings tab, billing; plus the member chat thread at 320–1440 px (P4.2 / P4.3a widths).
*/

beforeEach(function (): void {
    duskChatCleanup();
    duskMemberCleanup();
    duskMemberCleanup(DUSK_SECOND_PHONE);
});

afterEach(function (): void {
    duskChatCleanup();
    duskMemberCleanup();
    duskMemberCleanup(DUSK_SECOND_PHONE);
});

it('a11y: the member pages pass WCAG 2.1 A/AA, the report dialog included; the thread fits 320–1440 px', function (): void {
    $this->browse(function (Browser $browser): void {
        $bride = chatOnPlan(duskLiveMember($browser), PlanCode::Gold);
        $groom = chatOnPlan(duskSecondMember(), PlanCode::Gold);
        app(SendInterest::class)->handle($groom, $bride->profile, 'T01');
        app(AcceptInterest::class)->handle($bride, $groom->profile);
        $code = $groom->profile->code;

        $browser->resize(1280, 900);
        foreach (['/dashboard', '/me', '/profiles', '/matches', '/search', '/profile/'.$code, '/messages', '/messages?with='.$code,
            '/notifications', '/interests', '/likes', '/favorites', '/visitors', '/settings', '/settings?tab=notifications',
            '/settings?tab=account', '/settings?tab=blocked', '/billing', '/plans', '/help'] as $page) {
            $browser->visit($page)->waitFor('main#main')->waitUntilMissing('#preloader', 10)->pause(400);   // review M5: not the curtain
            assertAccessible($browser, $page);
        }

        // The report dialog in the open thread (P4.3a).
        $browser->visit('/messages?with='.$code)->waitFor('#msgText')->waitUntilMissing('#preloader', 10)
            ->script("window.dispatchEvent(new CustomEvent('open-modal', { detail: { name: 'report-chat' } }));");
        $browser->pause(400);
        assertAccessible($browser, 'chat report dialog');

        assertFitsWidths($browser, url('/favicon.ico'), [url('/messages'), url('/messages?with='.$code)]);
    });
});
