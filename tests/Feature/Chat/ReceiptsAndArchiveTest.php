<?php

declare(strict_types=1);

use App\Actions\Admin\Members\PurgeDeletedMember;
use App\Actions\Chat\MarkConversationRead;
use App\Actions\Chat\MarkMessagesDelivered;
use App\Actions\Chat\SetConversationArchived;
use App\Data\Chat\MessageData;
use App\Enums\MessageType;
use App\Enums\PlanCode;
use App\Enums\SettingKey;
use App\Events\Chat\ConversationUpdated;
use App\Events\Chat\MessagesDelivered;
use App\Events\Chat\MessagesRead;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Models\Block;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\PrivacySetting;
use App\Models\Setting;
use App\Models\User;
use App\Queries\Chat\InboxList;
use App\Queries\Chat\ThreadWindow;
use App\Queries\Chat\UnreadChats;
use App\Support\Facades\Settings;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
| P4.1 — receipts, unread counts, archive and retention (PRD M07 ticks ✓ / ✓✓ / blue ✓✓, R-M07-7
| symmetric read receipts, inbox filters, R-M07-6 retention on purge).
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
});

function receiptsOff(User $member): void
{
    $privacy = PrivacySetting::query()->whereKey($member->profile->id)->first() ?? new PrivacySetting;
    $privacy->forceFill(['profile_id' => $member->profile->id, 'read_receipts' => false])->save();
}

/** @return list<string|null> the ticks the member sees on their own messages, oldest first */
function ticksFor(User $member, Conversation $thread, bool $blue = true): array
{
    return collect(app(ThreadWindow::class)->latest($thread, $member->profile, $blue))
        ->filter(fn (MessageData $m): bool => $m->mine)->map(fn (MessageData $m): ?string => $m->tick)->values()->all();
}

it('F03: reading the thread marks the other member\'s messages read, zeroes the unread count and turns the sender\'s ticks blue', function (): void {
    [$groom, $bride, $thread] = chatConnected();
    chatSend($groom, $bride, 'One');
    chatSend($groom, $bride, 'Two');
    expect(ticksFor($groom, $thread))->toBe([MessageData::SENT, MessageData::SENT]);

    Event::fake([MessagesRead::class, ConversationUpdated::class]);
    $marked = app(MarkConversationRead::class)->handle($bride, $groom->profile);

    expect($marked)->toBe(2)
        ->and(ConversationParticipant::query()->where('profile_id', $bride->profile->id)->value('unread_count'))->toBe(0)
        ->and(Message::query()->where('sender_profile_id', $groom->profile->id)->whereNull('read_at')->count())->toBe(0)
        ->and(Message::query()->where('sender_profile_id', $groom->profile->id)->whereNull('delivered_at')->count())->toBe(0)
        ->and(ticksFor($groom, $thread))->toBe([MessageData::READ, MessageData::READ]);

    Event::assertDispatched(MessagesRead::class, function (MessagesRead $event) use ($thread, $bride): bool {
        return $event->broadcastOn()[0]->name === 'private-chat.'.$thread->id
            && $event->broadcastAs() === 'message.read'
            && array_keys($event->broadcastWith()) === ['reader_code', 'up_to_message_id', 'read_at']
            && $event->broadcastWith()['reader_code'] === $bride->profile->code;
    });
    // The reader's own other tabs / badge sync.
    Event::assertDispatched(ConversationUpdated::class, fn (ConversationUpdated $e): bool => $e->profileId === $bride->profile->id && $e->unread === 0);
});

it('F03: reading again with nothing new writes and announces nothing', function (): void {
    [$groom, $bride] = chatConnected();
    chatSend($groom, $bride);
    app(MarkConversationRead::class)->handle($bride, $groom->profile);

    Event::fake([MessagesRead::class, ConversationUpdated::class]);
    expect(app(MarkConversationRead::class)->handle($bride, $groom->profile))->toBe(0);
    Event::assertNothingDispatched();
});

it('R-M07-7: a reader with read receipts off is never announced as having read — the sender only sees ✓✓ delivered', function (): void {
    [$groom, $bride, $thread] = chatConnected();
    receiptsOff($bride);
    chatSend($groom, $bride);

    Event::fake([MessagesRead::class, MessagesDelivered::class, ConversationUpdated::class]);
    app(MarkConversationRead::class)->handle($bride, $groom->profile);

    Event::assertNotDispatched(MessagesRead::class);
    Event::assertDispatched(MessagesDelivered::class);
    expect(ticksFor($groom, $thread, blue: app(App\Domain\Chat\ReadReceipts::class)->shownBetween($groom->profile->id, $bride->profile->id)))
        ->toBe([MessageData::DELIVERED]);
});

it('R-M07-7: receipts are symmetric — a member who turned them off sees no blue ticks from others either', function (): void {
    [$groom, $bride] = chatConnected();
    receiptsOff($groom);
    $receipts = app(App\Domain\Chat\ReadReceipts::class);

    expect($receipts->enabled($bride->profile->id))->toBeTrue()
        ->and($receipts->enabled($groom->profile->id))->toBeFalse()
        ->and($receipts->shownBetween($groom->profile->id, $bride->profile->id))->toBeFalse();
});

it('security: marking read needs a thread the member is in (404), and never runs in a support session', function (): void {
    [$groom, $bride] = chatConnected();
    $stranger = bride('+919800000055');

    expect(fn () => app(MarkConversationRead::class)->handle($stranger, $groom->profile))->toThrow(NotFoundHttpException::class);

    chatSend($groom, $bride);
    chatImpersonate($bride);
    expect(fn () => app(MarkConversationRead::class)->handle($bride, $groom->profile))->toThrow(ImpersonationRestricted::class);
    expect(ConversationParticipant::query()->where('profile_id', $bride->profile->id)->value('unread_count'))->toBe(1);
});

it('✓✓ delivered: the recipient\'s open site marks waiting messages delivered and tells the sender — once', function (): void {
    [$groom, $bride, $thread] = chatConnected();
    chatSend($groom, $bride, 'One');
    chatSend($groom, $bride, 'Two');

    Event::fake([MessagesDelivered::class]);
    expect(app(MarkMessagesDelivered::class)->handle($bride->profile))->toBe(2)
        ->and(ticksFor($groom, $thread))->toBe([MessageData::DELIVERED, MessageData::DELIVERED]);
    Event::assertDispatched(MessagesDelivered::class, fn (MessagesDelivered $e): bool => $e->broadcastOn()[0]->name === 'private-chat.'.$thread->id
        && array_keys($e->broadcastWith()) === ['up_to_message_id']);

    expect(app(MarkMessagesDelivered::class)->handle($bride->profile))->toBe(0);
    Event::assertDispatchedTimes(MessagesDelivered::class, 1);
});

it('✓✓ delivered: never marks the member\'s own messages, nor messages from a blocked member, nor in a support session', function (): void {
    [$groom, $bride] = chatConnected(PlanCode::Gold, PlanCode::Gold);
    chatSend($groom, $bride, 'From him');
    chatSend($bride, $groom, 'From her');

    expect(app(MarkMessagesDelivered::class)->handle($groom->profile))->toBe(1);   // hers only
    expect(Message::query()->where('sender_profile_id', $groom->profile->id)->whereNull('delivered_at')->count())->toBe(1);

    chatImpersonate($bride);
    expect(app(MarkMessagesDelivered::class)->handle($bride->profile))->toBe(0);
    session()->flush();
    app(App\Services\Admin\Impersonation::class)->forget();

    Block::factory()->create(['blocker_profile_id' => $bride->profile->id, 'blocked_profile_id' => $groom->profile->id]);
    expect(app(MarkMessagesDelivered::class)->handle($bride->profile))->toBe(0);
});

it('unread total (chat badge): the sum over the member\'s threads, without blocked members\' threads', function (): void {
    [$groom, $bride] = chatConnected();
    chatSend($groom, $bride, 'One');
    chatSend($groom, $bride, 'Two');
    $unread = app(UnreadChats::class);

    expect($unread->total($bride->profile))->toBe(2)->and($unread->total($groom->profile))->toBe(0);

    Block::factory()->create(['blocker_profile_id' => $groom->profile->id, 'blocked_profile_id' => $bride->profile->id]);
    expect($unread->total($bride->profile))->toBe(0);
});

it('archive: moves the member\'s own side to Archived (the other member sees nothing change); unarchive brings it back', function (): void {
    [$groom, $bride] = chatConnected();
    $inbox = app(InboxList::class);
    $codes = fn (User $who, string $filter): array => collect($inbox->page($who, $who->profile, $filter, '', 20)['items'])->pluck('code')->all();

    app(SetConversationArchived::class)->handle($bride, $groom->profile, true);
    expect($codes($bride, InboxList::ALL))->toBe([])
        ->and($codes($bride, InboxList::ARCHIVED))->toBe([$groom->profile->code])
        ->and($codes($groom, InboxList::ALL))->toBe([$bride->profile->code]);

    app(SetConversationArchived::class)->handle($bride, $groom->profile, false);
    expect($codes($bride, InboxList::ALL))->toBe([$groom->profile->code]);
});

it('archive: only the member\'s own threads (404 otherwise), never in a support session', function (): void {
    [$groom, $bride] = chatConnected();
    $stranger = bride('+919800000044');

    expect(fn () => app(SetConversationArchived::class)->handle($stranger, $groom->profile, true))->toThrow(NotFoundHttpException::class);

    chatImpersonate($bride);
    expect(fn () => app(SetConversationArchived::class)->handle($bride, $groom->profile, true))->toThrow(ImpersonationRestricted::class);
});

it('inbox: lists threads newest first with previews, filters and search', function (): void {
    [$groom, $bride] = chatConnected(PlanCode::Gold, PlanCode::Gold);
    $second = chatOnPlan(bride('+919800000033'), PlanCode::Gold);
    app(App\Actions\Engagement\SendInterest::class)->handle($groom, $second->profile, 'T01');
    app(App\Actions\Engagement\AcceptInterest::class)->handle($second, $groom->profile);
    $second->profile->forceFill(['first_name' => 'Zeena'])->save();
    $inbox = app(InboxList::class);
    $page = fn (string $filter = InboxList::ALL, string $search = '', bool $hide = false): array => $inbox->page($groom, $groom->profile, $filter, $search, 20, $hide)['items'];

    $this->travel(1)->minutes();
    chatSend($bride, $groom, 'Hello from the bride');
    $this->travel(1)->minutes();
    chatSend($second, $groom, 'Hello from Zeena <script>alert(1)</script>');

    $items = $page();
    expect(collect($items)->pluck('code')->all())->toBe([$second->profile->code, $bride->profile->code])
        ->and($items[0]->preview)->toBe('Hello from Zeena <script>alert(1)</script>')
        ->and($items[0]->unread)->toBe(1)
        ->and($items[0]->lastFromMe)->toBeFalse();

    expect(collect($page(InboxList::ALL, 'zee'))->pluck('code')->all())->toBe([$second->profile->code])
        ->and(collect($page(InboxList::ALL, '%'))->pluck('code')->all())->toBe([]);

    app(MarkConversationRead::class)->handle($groom, $second->profile);
    expect(collect($page(InboxList::UNREAD))->pluck('code')->all())->toBe([$bride->profile->code])
        ->and($inbox->unreadThreads($groom->profile))->toBe(1);

    expect(collect($page(InboxList::ALL, '', true))->pluck('preview')->filter()->all())->toBe([]);

    Block::factory()->create(['blocker_profile_id' => $second->profile->id, 'blocked_profile_id' => $groom->profile->id]);
    expect(collect($page())->pluck('code')->all())->toBe([$bride->profile->code]);
});

it('R-M07-6: purging a deleted member keeps the thread for the other member; once both are purged it goes with its messages', function (): void {
    [$groom, $bride, $thread] = chatConnected(PlanCode::Gold, PlanCode::Gold);
    chatSend($groom, $bride, 'Hi');
    chatSend($bride, $groom, 'Hello');
    $purge = app(PurgeDeletedMember::class);
    $days = Settings::int(SettingKey::MembersPurgeAfterDays);

    $bride->delete();
    $this->travel($days + 1)->days();
    expect($purge->handle(User::withTrashed()->findOrFail($bride->id)))->toBeTrue();

    expect(Conversation::query()->whereKey($thread->id)->exists())->toBeTrue()
        ->and(Message::query()->where('conversation_id', $thread->id)->where('type', MessageType::Text->value)->count())->toBe(2);

    $groom->delete();
    $this->travel($days + 1)->days();
    expect($purge->handle(User::withTrashed()->findOrFail($groom->id)))->toBeTrue();

    expect(Conversation::query()->whereKey($thread->id)->exists())->toBeFalse()
        ->and(Message::query()->where('conversation_id', $thread->id)->count())->toBe(0);
});

it('MarkConversationRead / delivered need no plan: a Free member reads everything (R-M07-2)', function (): void {
    [$groom, $bride] = chatConnected(PlanCode::Gold, null);
    chatSend($groom, $bride);

    expect(app(MarkConversationRead::class)->handle($bride, $groom->profile))->toBe(1);
});

it('A15: the chat limits are settings with the PRD defaults (30 a minute, 2,000 characters)', function (): void {
    expect(SettingKey::ChatMaxPerMinute->default())->toBe(30)
        ->and(SettingKey::ChatMaxLength->default())->toBe(2000);

    Setting::factory()->keyed(SettingKey::ChatMaxPerMinute, 10)->create();
    Settings::flush();
    expect(Settings::int(SettingKey::ChatMaxPerMinute))->toBe(10);
});
