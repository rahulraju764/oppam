<?php

declare(strict_types=1);

use App\Actions\Engagement\AcceptInterest;
use App\Actions\Engagement\SendInterest;
use App\Enums\MessageType;
use App\Enums\PlanCode;
use App\Models\Conversation;
use App\Models\Message;
use Laravel\Dusk\Browser;

/*
| P4.1 — "Done when" in two real browsers over Reverb (needs `composer dev`): a paid member writes,
| the other member's open thread shows it in < 1 s, she reads it and his ticks turn blue; the Free
| member's composer allows one reply then locks, and a bypass from the browser is refused by the
| server; the same client id sent twice stores one message (PRD M07 acceptance, F03).
| Uses duskSecondMember() (tests/Support/dusk-member.php).
*/

beforeEach(function (): void {
    duskChatCleanup();
});

afterEach(function (): void {
    duskChatCleanup();
});

function duskChatCleanup(): void
{
    // Conversations have no cascade from profiles: remove the pair's threads before the members.
    $ids = App\Models\Profile::withTrashed()->whereIn('user_id', App\Models\User::withTrashed()
        ->whereIn('phone', [DUSK_MEMBER_PHONE, DUSK_SECOND_PHONE])->select('id'))->pluck('id');
    Conversation::query()->whereIn('id', App\Models\ConversationParticipant::query()->whereIn('profile_id', $ids)->select('conversation_id'))->delete();
    App\Models\Subscription::query()->whereIn('profile_id', $ids)->delete();

    duskMemberCleanup();
    duskMemberCleanup(DUSK_SECOND_PHONE);
}

/** Wait until this browser follows the thread's private channel (Livewire's Echo listener). */
function duskChatSubscribed(Browser $browser, Conversation $thread): void
{
    $browser->waitUsing(20, 100, fn (): bool => (bool) $browser->script(
        "return window.Echo?.connector?.channels?.['private-chat.{$thread->id}']?.subscription?.subscribed === true;")[0]);
}

it('P4.1: a message reaches the other member\'s open thread in under a second, read receipts turn blue, the Free member replies once then is locked', function (): void {
    $this->browse(function (Browser $first, Browser $second): void {
        $bride = duskLiveMember($first);   // the Dusk bride, signed in, Free plan
        $groom = chatOnPlan(duskSecondMember(), PlanCode::Gold);
        app(SendInterest::class)->handle($groom, $bride->profile, 'T01');
        $thread = app(AcceptInterest::class)->handle($bride, $groom->profile);

        $first->resize(1280, 900)->visit('/messages?with='.$groom->profile->code)
            ->waitForText('You are now connected')
            ->assertSee('Upgrade to chat');   // Free, and he hasn't written yet
        duskChatSubscribed($first, $thread);

        $second->resize(1280, 900)->visit('/login')
            ->type('#loginId', 'dusk.groom@example.com')
            ->type('#loginPassword', TEST_MEMBER_PASSWORD)
            ->click('.login-form button[type="submit"]')
            ->waitForLocation('/dashboard')
            ->visit('/messages?with='.$bride->profile->code)
            ->waitFor('#msgText');
        duskChatSubscribed($second, $thread);

        // He writes; her open thread shows it without a reload (M07 acceptance: < 1 s p95). Timed in
        // the browsers themselves (same machine clock), so WebDriver's round trips don't count:
        //   his Enter → the event reaching her page (server: request + action + broadcast), and
        //   the event → the text on her screen (this code's client side, always < 250 ms).
        // End to end < 1 s is asserted where PHP runs with OPcache, as production does; local XAMPP
        // PHP without it spends ~0.5–0.8 s compiling the framework on every request, so there the
        // bound is 2 s and the run says why.
        $first->script(sprintf("window.__seenAt = null; window.__eventAt = null;
            window.Echo.private('chat.%s').listen('.message.sent', () => { window.__eventAt ??= Date.now(); });
            new MutationObserver((_, o) => {
                if (document.querySelector('.msg-thread-body-scroll')?.innerText.includes('Hello from Dusk')) { window.__seenAt = Date.now(); o.disconnect(); }
            }).observe(document.querySelector('.msg-thread-body-scroll'), { childList: true, subtree: true, characterData: true });", $thread->id));
        $second->type('#msgText', 'Hello from Dusk')
            ->script("document.querySelector('#msgText').addEventListener('keydown', (e) => { if (e.key === 'Enter') window.__sentAt = Date.now(); }, { capture: true, once: true });");
        $second->keys('#msgText', '{enter}');
        $first->waitUsing(5, 25, fn (): bool => (bool) $first->script('return window.__seenAt !== null && window.__eventAt !== null;')[0]);
        $sentAt = (int) $second->script('return window.__sentAt;')[0];
        [$eventAt, $seenAt] = $first->script('return [window.__eventAt, window.__seenAt];')[0];
        $total = (int) $seenAt - $sentAt;
        $onScreen = (int) $seenAt - (int) $eventAt;
        $opcache = extension_loaded('Zend OPcache') && (bool) ini_get('opcache.enable');
        fwrite(STDERR, sprintf("\n  chat delivery: %d ms end to end (server %d ms, on screen %d ms after the event)%s\n",
            $total, (int) $eventAt - $sentAt, $onScreen, $opcache ? '' : ' — PHP without OPcache: end-to-end bound 2 s'));
        expect($onScreen)->toBeLessThan(250)
            ->and($total)->toBeLessThan($opcache ? 1000 : 2000);

        // His optimistic bubble was replaced by the stored message; she read it → blue ticks for him.
        $second->waitUsing(10, 100, fn (): bool => (bool) $second->script('return document.querySelectorAll(".msg-bubble.is-pending").length === 0;')[0])
            ->waitFor('.msg-bubble.is-me .msg-tick.is-read', 10);

        // Now she may reply once (R-M07-3)…
        $first->waitFor('#msgText', 10)
            ->assertSee('You have 1 free reply left in this conversation.')
            ->type('#msgText', 'Thank you, hello')
            ->keys('#msgText', '{enter}')
            ->waitUntilMissing('#msgText', 10)
            ->assertSee('You have used your free reply in this conversation. Upgrade to chat.');
        $second->waitForText('Thank you, hello', 5);

        // …and a reply forced from the browser is refused by the server, nothing stored.
        $first->script(sprintf("Livewire.find(document.querySelector('[data-chat-thread]').closest('[wire\\\\:id]').getAttribute('wire:id')).send('%s', 'Bypassing the lock');",
            (string) Str::uuid()));
        $first->pause(1500);
        expect(Message::query()->where('body', 'Bypassing the lock')->exists())->toBeFalse()
            ->and(Message::query()->where('sender_profile_id', $bride->profile->id)->where('type', MessageType::Text->value)->count())->toBe(1);

        // The same client id sent twice from the browser is ONE message (§9.7.3).
        $clientId = (string) Str::uuid();
        $second->script(sprintf("const c = Livewire.find(document.querySelector('[data-chat-thread]').closest('[wire\\\\:id]').getAttribute('wire:id')); c.send('%1\$s', 'Twice'); c.send('%1\$s', 'Twice');", $clientId));
        $second->waitUsing(10, 100, fn (): bool => Message::query()->where('client_id', $clientId)->exists());
        $second->pause(1000);
        expect(Message::query()->where('client_id', $clientId)->count())->toBe(1);
    });
});
