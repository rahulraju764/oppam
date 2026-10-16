<?php

declare(strict_types=1);

use App\Enums\ConversationStatus;
use App\Enums\MessageType;
use App\Enums\PlanCode;
use App\Livewire\Member\Chat\Inbox;
use App\Livewire\Member\Chat\Messages;
use App\Livewire\Member\Chat\Thread;
use App\Livewire\Shared\ChatBadge;
use App\Models\ConversationParticipant;
use App\Models\Message;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P4.1 — the chat screens (PRD M07, template messages.php): /messages (3/6/3, one pane on phones),
| the inbox (live list, filters, search, archive), the thread (messages, ticks, composer, Free
| lock, support session) and the header chat badge.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
});

it('/messages needs a signed-in member', function (): void {
    $this->get(memberUrl('/messages'))->assertRedirect(memberUrl('/login'));
});

it('/messages lists the member\'s conversations; without ?with= the thread pane asks to choose one', function (): void {
    [$groom, $bride] = chatConnected();
    chatSend($groom, $bride, 'Hello Anna');

    $this->actingAs($bride, 'web')->get(memberUrl('/messages'))
        ->assertOk()
        ->assertSee('Conversations')
        ->assertSee($groom->profile->first_name)
        ->assertSee('Hello Anna')
        ->assertSee('Choose a conversation')
        ->assertSeeHtml('href="'.route('member.messages', ['with' => $groom->profile->code]).'"');
});

it('/messages?with=CODE opens the thread: the SYSTEM message translated, messages escaped, day separator, the other member\'s profile link', function (): void {
    [$groom, $bride] = chatConnected(PlanCode::Gold, PlanCode::Gold);
    chatSend($groom, $bride, '<script>alert("x")</script> hello');

    $this->actingAs($bride, 'web')->get(memberUrl('/messages?with='.$groom->profile->code))
        ->assertOk()
        ->assertSee('You are now connected')
        ->assertSee('Today')
        ->assertDontSeeHtml('<script>alert("x")</script>')
        ->assertSee('<script>alert("x")</script> hello')
        ->assertSeeHtml('data-chat-thread="'.$groom->profile->code.'"')
        ->assertSeeHtml('role="log"')
        ->assertSee('View profile');
});

it('security: a thread with a member the viewer is not connected with is 404; a malformed code opens no thread', function (): void {
    [$groom] = chatConnected();
    $stranger = bride('+919800000011');

    $this->actingAs($groom, 'web')->get(memberUrl('/messages?with='.$stranger->profile->code))->assertNotFound();
    $this->actingAs($groom, 'web')->get(memberUrl('/messages?with=OPM99999999'))->assertNotFound();
    $this->actingAs($groom, 'web')->get(memberUrl('/messages?with=../etc'))->assertOk()->assertSee('Choose a conversation');
});

it('opening the thread marks it read; a support session reads no message text and marks nothing', function (): void {
    [$groom, $bride] = chatConnected();
    chatSend($groom, $bride, 'Private words');

    chatImpersonate($bride);
    Livewire::actingAs($bride, 'web')->test(Thread::class, ['withCode' => $groom->profile->code])
        ->assertDontSee('Private words')
        ->assertSee('Messages are private')
        ->assertDontSeeHtml('id="msgText"');
    expect(ConversationParticipant::query()->where('profile_id', $bride->profile->id)->value('unread_count'))->toBe(1);

    session()->flush();
    app(App\Services\Admin\Impersonation::class)->forget();
    Livewire::actingAs($bride, 'web')->test(Thread::class, ['withCode' => $groom->profile->code])->assertSee('Private words');
    expect(ConversationParticipant::query()->where('profile_id', $bride->profile->id)->value('unread_count'))->toBe(0);
});

it('send(): stores the message, re-renders it with its client id and tells the inbox', function (): void {
    [$groom, $bride] = chatConnected();
    $clientId = (string) Str::uuid();

    Livewire::actingAs($groom, 'web')->test(Thread::class, ['withCode' => $bride->profile->code])
        ->call('send', $clientId, 'Hello from the thread')
        ->assertDispatched('chat-inbox-changed')
        ->assertNotDispatched('chat-send-failed')
        ->assertSee('Hello from the thread')
        ->assertSeeHtml('data-client-id="'.$clientId.'"')
        ->assertSee('Sent');

    expect(Message::query()->where('client_id', $clientId)->count())->toBe(1);
});

it('R-M07-3: the Free composer is locked with "Upgrade to chat"; after the other member writes it allows ONE reply, then locks; a bypass is refused', function (): void {
    [$groom, $bride] = chatConnected(PlanCode::Gold, null);

    $thread = Livewire::actingAs($bride, 'web')->test(Thread::class, ['withCode' => $groom->profile->code])
        ->assertSee('Upgrade to chat')
        ->assertDontSeeHtml('id="msgText"');

    $clientId = (string) Str::uuid();
    $thread->call('send', $clientId, 'Sneaky first message')
        ->assertDispatched('chat-send-failed', clientId: $clientId);
    expect(Message::query()->where('sender_profile_id', $bride->profile->id)->count())->toBe(0);

    chatSend($groom, $bride, 'Hello Anna');
    $thread = Livewire::actingAs($bride, 'web')->test(Thread::class, ['withCode' => $groom->profile->code])
        ->assertSeeHtml('id="msgText"')
        ->assertSee('You have 1 free reply left in this conversation.');

    $thread->call('send', (string) Str::uuid(), 'Thank you')
        ->assertSee('Upgrade to chat')
        ->assertDontSeeHtml('id="msgText"');
});

it('R-M07-5: a frozen thread shows its messages and no composer', function (): void {
    [$groom, $bride, $thread] = chatConnected();
    chatSend($groom, $bride, 'Before the freeze');
    $thread->forceFill(['status' => ConversationStatus::Frozen])->save();

    Livewire::actingAs($bride, 'web')->test(Thread::class, ['withCode' => $groom->profile->code])
        ->assertSee('Before the freeze')
        ->assertSee('This conversation is closed for new messages.')
        ->assertDontSeeHtml('id="msgText"');
});

it('tampering with the thread\'s locked code is refused; the listeners name the thread\'s own channel', function (): void {
    [$groom, $bride, $conversation] = chatConnected();

    $component = Livewire::actingAs($groom, 'web')->test(Thread::class, ['withCode' => $bride->profile->code]);
    expect($component->instance()->getListeners())->toHaveKey('echo-private:chat.'.$conversation->id.',.message.sent');

    expect(fn () => $component->set('withCode', 'OPM1'))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('a message from the other member asks the open tab to mark it read; the member\'s own echo does not', function (): void {
    [$groom, $bride] = chatConnected();

    Livewire::actingAs($bride, 'web')->test(Thread::class, ['withCode' => $groom->profile->code])
        ->call('onMessage', ['sender_code' => $groom->profile->code])->assertDispatched('chat-message-arrived');
    Livewire::actingAs($bride, 'web')->test(Thread::class, ['withCode' => $groom->profile->code])
        ->call('onMessage', ['sender_code' => $bride->profile->code])->assertNotDispatched('chat-message-arrived');
});

it('markRead(): marks the other member\'s new messages read and refreshes the inbox', function (): void {
    [$groom, $bride] = chatConnected();
    $component = Livewire::actingAs($bride, 'web')->test(Thread::class, ['withCode' => $groom->profile->code]);
    chatSend($groom, $bride, 'Arrived while open');

    $component->call('markRead')->assertDispatched('chat-inbox-changed');
    expect(ConversationParticipant::query()->where('profile_id', $bride->profile->id)->value('unread_count'))->toBe(0);
});

it('inbox: filters, search, archive / unarchive with toasts, and the designed empty states', function (): void {
    [$groom, $bride] = chatConnected();

    $inbox = Livewire::actingAs($bride, 'web')->test(Inbox::class)
        ->assertSee($groom->profile->first_name)
        ->assertSeeHtml('wire:loading.class="is-loading"');

    $inbox->call('archive', $groom->profile->code)
        ->assertDispatched('toast', type: 'success')
        ->assertSee('No conversations yet')
        ->call('show', 'archived')->assertSee($groom->profile->first_name)
        ->call('unarchive', $groom->profile->code)->assertSee('Nothing archived')
        ->call('show', 'unread')->assertSee('All caught up')
        ->call('show', 'all')->set('search', 'zzz')->assertSee('No conversation with that name')
        ->call('show', 'nonsense')->assertSet('filter', 'all');
});

it('inbox: archiving someone else\'s thread, or a made-up code, is refused with a toast', function (): void {
    [$groom] = chatConnected();
    $stranger = bride('+919800000012');

    Livewire::actingAs($groom, 'web')->test(Inbox::class)
        ->call('archive', $stranger->profile->code)->assertDispatched('toast', type: 'danger')
        ->call('archive', 'DROP TABLE')->assertDispatched('toast', type: 'danger');
});

it('inbox: listens on the member\'s own inbox channel only', function (): void {
    [$groom] = chatConnected();

    $listeners = Livewire::actingAs($groom, 'web')->test(Inbox::class)->instance()->getListeners();

    expect($listeners)->toHaveKey('echo-private:inbox.'.$groom->profile->id.',.conversation.updated');
});

it('chat badge: the unread total in the header; a live update sets it and announces a new message; loading the site marks waiting messages delivered', function (): void {
    [$groom, $bride] = chatConnected();
    chatSend($groom, $bride, 'One');
    chatSend($groom, $bride, 'Two');

    $badge = Livewire::actingAs($bride, 'web')->test(ChatBadge::class, ['variant' => 'desktop'])
        ->assertSet('unread', 2)
        ->assertSeeHtml('aria-label="Messages (2 unread)"');
    expect(Message::query()->where('sender_profile_id', $groom->profile->id)->whereNull('delivered_at')->count())->toBe(0);

    $badge->call('onUpdated', ['total_unread' => 3, 'from_me' => false, 'with_code' => $groom->profile->code, 'unread' => 3, 'name' => 'Arun', 'preview' => 'Three'])
        ->assertSet('unread', 3)
        ->assertDispatched('chat-new-message', code: $groom->profile->code);

    $badge->call('onUpdated', ['total_unread' => 0, 'from_me' => true, 'with_code' => $groom->profile->code, 'unread' => 0])
        ->assertSet('unread', 0)
        ->assertNotDispatched('chat-new-message');
});

it('chat badge: the mobile badge only counts; a tampered count is refused', function (): void {
    [$groom, $bride] = chatConnected();
    chatSend($groom, $bride);

    $badge = Livewire::actingAs($bride, 'web')->test(ChatBadge::class, ['variant' => 'mobile'])->assertSet('unread', 1);
    expect(Message::query()->whereNull('delivered_at')->where('type', MessageType::Text->value)->count())->toBe(1);

    expect(fn () => $badge->set('unread', 99))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('the member header carries the Messages badge on every member page', function (): void {
    [$groom] = chatConnected();

    $this->actingAs($groom, 'web')->get(memberUrl('/dashboard'))
        ->assertOk()
        ->assertSeeHtml('aria-label="Messages (0 unread)"');
});

it('the Chat button of a connected pair now links to the thread', function (): void {
    [$groom, $bride] = chatConnected();

    $this->actingAs($groom, 'web')->get(memberUrl('/profile/'.$bride->profile->code))
        ->assertOk()
        ->assertSeeHtml("interestButton('connected'")
        ->assertSee('messages?with='.$bride->profile->code, false);
});

it('Messages::validCode accepts profile codes only', function (string $code, string $expected): void {
    expect(Messages::validCode($code))->toBe($expected);
})->with([
    ['OPM12370', 'OPM12370'],
    ['opm12370', ''],
    ['OPM', ''],
    ['OPM1<script>', ''],
    ['BRK1042', ''],
]);
