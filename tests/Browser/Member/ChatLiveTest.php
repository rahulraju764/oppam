<?php

declare(strict_types=1);

use App\Actions\Chat\UnsendMessage;
use App\Actions\Engagement\AcceptInterest;
use App\Actions\Engagement\SendInterest;
use App\Enums\PlanCode;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Laravel\Dusk\Browser;

/*
| P4.2 — the M07 acceptance list in real browsers over Reverb (needs `composer dev`):
|  - typing shows within 500 ms and clears 4 s after the last keystroke;
|  - after a 60 s network drop, reconnecting back-fills every missed message, in order;
|  - an unsent message turns into "This message was deleted" on the other screen, live;
|  - polling fallback (no socket): the thread still shows new messages, by re-reading every 10 s.
| Both Dusk members are Gold here (both can write).
*/

beforeEach(function (): void {
    duskChatCleanup();
});

afterEach(function (): void {
    duskChatCleanup();
});

/** @return array{0: User, 1: User, 2: Conversation} the bride (signed in in $first), the groom, their thread */
function duskChatPair(Browser $first): array
{
    $bride = chatOnPlan(duskLiveMember($first), PlanCode::Gold);
    $groom = chatOnPlan(duskSecondMember(), PlanCode::Gold);
    app(SendInterest::class)->handle($groom, $bride->profile, 'T01');
    $thread = app(AcceptInterest::class)->handle($bride, $groom->profile);

    return [$bride, $groom, $thread];
}

function duskJoinedTyping(Browser $browser, Conversation $thread): void
{
    $browser->waitUsing(20, 100, fn (): bool => (bool) $browser->script(
        "return window.Echo?.connector?.channels?.['presence-presence-chat.{$thread->id}']?.subscription?.subscribed === true;")[0]);
}

it('M07 acceptance: the typing indicator shows within 500 ms and clears 4 s after the last keystroke', function (): void {
    $this->browse(function (Browser $first, Browser $second): void {
        [$bride, $groom, $thread] = duskChatPair($first);

        $first->resize(1280, 900)->visit('/messages?with='.$groom->profile->code)->waitFor('#msgText');
        duskJoinedTyping($first, $thread);
        duskSignInSecond($second, '/messages?with='.$bride->profile->code);
        $second->waitFor('#msgText');
        duskJoinedTyping($second, $thread);

        $first->script("window.__typingAt = null; window.__typingGoneAt = null;
            new MutationObserver(() => {
                const shown = getComputedStyle(document.querySelector('.msg-typing')).display !== 'none';
                if (shown && window.__typingAt === null) window.__typingAt = Date.now();
                if (! shown && window.__typingAt !== null && window.__typingGoneAt === null) window.__typingGoneAt = Date.now();
            }).observe(document.querySelector('.msg-status'), { attributes: true, subtree: true });");
        $second->script("document.querySelector('#msgText').addEventListener('input', () => { window.__lastKeyAt = Date.now(); }, { capture: true });");

        $second->type('#msgText', 'H');
        $first->waitUsing(5, 25, fn (): bool => (bool) $first->script('return window.__typingAt !== null;')[0]);
        $shownAfter = (int) $first->script('return window.__typingAt;')[0] - (int) $second->script('return window.__lastKeyAt;')[0];
        expect($shownAfter)->toBeLessThan(500);

        $first->waitUsing(8, 50, fn (): bool => (bool) $first->script('return window.__typingGoneAt !== null;')[0]);
        $goneAfter = (int) $first->script('return window.__typingGoneAt;')[0] - (int) $second->script('return window.__lastKeyAt;')[0];
        expect($goneAfter)->toBeGreaterThanOrEqual(3900)->toBeLessThan(4600);
        fwrite(STDERR, "\n  typing: shown after {$shownAfter} ms, cleared after {$goneAfter} ms\n");
    });
});

it('M07 acceptance: after a 60 s network drop, reconnecting back-fills every missed message in order; unsend shows live', function (): void {
    $this->browse(function (Browser $first, Browser $second): void {
        [$bride, $groom, $thread] = duskChatPair($first);

        $first->resize(1280, 900)->visit('/messages?with='.$groom->profile->code)->waitFor('#msgText');
        duskChatSubscribed($first, $thread);

        // Her network drops: the socket goes away for 60 s while he writes three messages.
        $first->script('window.Echo.connector.pusher.disconnect();');
        $first->waitUsing(10, 100, fn (): bool => (bool) $first->script("return window.Echo.connector.pusher.connection.state === 'disconnected';")[0]);
        $sent = [];
        foreach (['Missed one', 'Missed two', 'Missed three'] as $text) {
            $sent[] = chatSend($groom, $bride, $text)->id;
        }
        $first->pause(60_000)->assertDontSee('Missed one');

        // Back online: the thread re-reads everything newer than its cursor.
        $first->script('window.Echo.connector.pusher.connect();');
        $first->waitUsing(20, 200, fn (): bool => (bool) $first->script(
            "return document.querySelector('.msg-thread-body-scroll').innerText.includes('Missed three');")[0]);
        $order = $first->script("return [...document.querySelectorAll('.msg-thread-body-scroll [data-message-id]')].map((el) => el.dataset.messageId);")[0];
        expect(array_values(array_intersect($order, $sent)))->toBe($sent);

        // He unsends the last one: her open thread shows the tombstone without a reload.
        duskChatSubscribed($first, $thread);
        app(UnsendMessage::class)->handle($groom, $sent[2]);
        $first->waitForText('This message was deleted', 10);
        expect((bool) $first->script("return document.querySelector('.msg-thread-body-scroll').innerText.includes('Missed three');")[0])->toBeFalse();
        $first->waitUsing(15, 200, fn (): bool => ! str_contains((string) $first->script("return document.querySelector('.msg-list').innerText;")[0], 'Missed three'));   // the inbox preview follows (queued signal)
        expect(Message::query()->whereKey($sent[2])->value('body'))->toBeNull();
    });
});

it('§9.7.2 polling fallback: without a socket the open thread still shows new messages (re-read every 10 s)', function (): void {
    $this->browse(function (Browser $first): void {
        [$bride, $groom] = duskChatPair($first);

        $first->resize(1280, 900)->visit('/messages?with='.$groom->profile->code)->waitFor('#msgText');
        // No socket (a corporate firewall): the realtime layer gave up and announced the fallback.
        $first->script("window.Echo.connector.pusher.disconnect(); window.dispatchEvent(new CustomEvent('realtime-fallback'));");

        chatSend($groom, $bride, 'Arrives by polling');
        $first->waitForText('Arrives by polling', 15);
    });
});
