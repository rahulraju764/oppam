<?php

declare(strict_types=1);

use App\Actions\Admin\Chat\SetConversationFrozen;
use App\Actions\Engagement\AcceptInterest;
use App\Actions\Engagement\SendInterest;
use App\Enums\PlanCode;
use App\Models\AdminUser;
use Laravel\Dusk\Browser;

/*
| P4.3a — the A13 "Done when" in real browsers over Reverb (needs `composer dev`):
|  - freezing a conversation makes BOTH members' composers read-only within 2 s, without a reload;
|  - a flagged message raises the admin's "Flagged messages" badge live.
*/

beforeEach(function (): void {
    duskChatCleanup();
    duskAdminCleanup();
});

afterEach(function (): void {
    duskChatCleanup();
    duskAdminCleanup();
});

it('A13 acceptance: freezing a conversation makes both members\' composers read-only within 2 s', function (): void {
    $this->browse(function (Browser $first, Browser $second): void {
        $bride = chatOnPlan(duskLiveMember($first), PlanCode::Gold);
        $groom = chatOnPlan(duskSecondMember(), PlanCode::Gold);
        app(SendInterest::class)->handle($groom, $bride->profile, 'T01');
        $thread = app(AcceptInterest::class)->handle($bride, $groom->profile);

        $first->resize(1280, 900)->visit('/messages?with='.$groom->profile->code)->waitFor('#msgText');
        duskChatSubscribed($first, $thread);
        duskSignInSecond($second, '/messages?with='.$bride->profile->code);
        $second->waitFor('#msgText');
        duskChatSubscribed($second, $thread);

        $watch = "window.__lockedAt = null; new MutationObserver(() => {
            if (window.__lockedAt === null && document.querySelector('.msg-composer.is-locked')) window.__lockedAt = Date.now();
        }).observe(document.body, { childList: true, subtree: true });";
        $first->script($watch);
        $second->script($watch);

        $admin = duskAdmin();
        $startedAt = (int) round(microtime(true) * 1000);
        app(SetConversationFrozen::class)->handle(AdminUser::query()->findOrFail($admin->id), $thread, true, 'Dusk: reviewing a report');

        foreach ([$first, $second] as $browser) {
            $browser->waitUsing(5, 50, fn (): bool => (bool) $browser->script('return window.__lockedAt !== null;')[0])
                ->assertMissing('#msgText')
                ->assertSee('This conversation is closed for new messages.');
            $after = (int) $browser->script('return window.__lockedAt;')[0] - $startedAt;
            expect($after)->toBeLessThan(2000);
            fwrite(STDERR, "\n  freeze: composer read-only {$after} ms after the moderator's action\n");
        }
    });
});

it('A13: a message flagged as it is sent raises the admin\'s "Flagged messages" badge live', function (): void {
    $this->browse(function (Browser $browser): void {
        $bride = duskLiveMember($browser);
        $groom = chatOnPlan(duskSecondMember(), PlanCode::Gold);
        app(SendInterest::class)->handle($groom, $bride->profile, 'T01');
        app(AcceptInterest::class)->handle($bride, $groom->profile);

        duskSignInAdmin($browser);
        $badge = '.admin-nav-badge[data-queue="chat.flags"]';
        $number = fn (): int => (int) preg_replace('/\D/', '', (string) $browser->script(
            "return document.querySelector('{$badge}')?.textContent ?? '0';")[0]);

        $browser->resize(1280, 900)->visit(adminDuskUrl('/chat/flags'))
            ->waitForText('Flagged messages')
            ->waitUsing(15, 100, fn (): bool => (bool) $browser->script("return window.Alpine?.store('adminLive')?.status === 'up';")[0])
            // The socket being up isn't enough: a count sent before the channel subscription lands is missed.
            ->waitUsing(20, 100, fn (): bool => (bool) $browser->script(
                "return window.Echo?.connector?.channels?.['private-admin.queues']?.subscription?.subscribed === true;")[0]);
        $before = $number();

        chatSend($groom->refresh(), $bride->refresh(), 'call me on 98470 12345');   // masked + flagged; the worker broadcasts the count

        $browser->waitUsing(20, 200, fn (): bool => $number() === $before + 1)->assertVisible($badge);
    });
});
