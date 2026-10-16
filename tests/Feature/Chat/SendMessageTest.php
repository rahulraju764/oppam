<?php

declare(strict_types=1);

use App\Domain\Chat\ChatAccess;
use App\Enums\ChatSendState;
use App\Enums\ConversationStatus;
use App\Enums\MessageType;
use App\Enums\PlanCode;
use App\Enums\ProfileStatus;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Events\Chat\ConversationUpdated;
use App\Events\Chat\MessageSent;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Chat\ChatRefused;
use App\Models\Block;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\Profile;
use App\Models\Setting;
use App\Support\Facades\Settings;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
| P4.1 — sending a chat message (PRD M07 send flow, F03, R-M07-1..4, §9.4, §9.7.3): what is
| written, what is announced, the plan rules (paid send, the Free member's one reply), and every
| way it must refuse (security matrix: wrong member, blocked, frozen / closed, gone, suspended,
| invalid input, rate limit, support session) with nothing written.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
});

function sendFails(callable $send): Throwable
{
    try {
        $send();
    } catch (Throwable $e) {
        return $e;
    }

    throw new RuntimeException('Expected the send to be refused.');
}

it('F03: a paid member\'s message is stored, counted unread for the other member and announced on the thread and both inboxes', function (): void {
    [$groom, $bride, $thread] = chatConnected();
    Event::fake([MessageSent::class, ConversationUpdated::class]);
    $clientId = (string) Str::uuid();

    $message = chatSend($groom, $bride, "  Hello Anna \n ", $clientId);

    expect($message->type)->toBe(MessageType::Text)
        ->and($message->body)->toBe('Hello Anna')
        ->and($message->sender_profile_id)->toBe($groom->profile->id)
        ->and($message->client_id)->toBe($clientId);

    $thread->refresh();
    expect($thread->last_message_id)->toBe($message->id)
        ->and(ConversationParticipant::query()->where('profile_id', $bride->profile->id)->value('unread_count'))->toBe(1)
        ->and(ConversationParticipant::query()->where('profile_id', $groom->profile->id)->value('unread_count'))->toBe(0)
        ->and(ConversationParticipant::query()->where('profile_id', $groom->profile->id)->value('last_read_message_id'))->toBe($message->id);

    Event::assertDispatched(MessageSent::class, function (MessageSent $event) use ($thread, $message, $groom, $clientId): bool {
        expect($event->broadcastOn()[0]->name)->toBe('private-chat.'.$thread->id)
            ->and($event->broadcastAs())->toBe('message.sent')
            ->and($event->broadcastWith())->toBe([
                'id' => $message->id, 'client_id' => $clientId, 'sender_code' => $groom->profile->code,
                'type' => 'TEXT', 'body' => 'Hello Anna', 'created_at' => $message->created_at->toIso8601String(),
            ]);

        return true;
    });

    Event::assertDispatchedTimes(ConversationUpdated::class, 2);
    Event::assertDispatched(ConversationUpdated::class, function (ConversationUpdated $event) use ($bride, $groom): bool {
        if ($event->profileId !== $bride->profile->id) {
            return false;
        }

        expect($event->broadcastOn()[0]->name)->toBe('private-inbox.'.$bride->profile->id)
            ->and($event->broadcastWith())->toMatchArray([
                'with_code' => $groom->profile->code, 'name' => $groom->profile->first_name, 'preview' => 'Hello Anna',
                'unread' => 1, 'total_unread' => 1, 'from_me' => false,
            ])
            ->and(json_encode($event->broadcastWith()))->not->toContain($groom->profile->id)->not->toContain((string) $groom->phone);

        return true;
    });
});

it('§9.7.3: sending the same client id twice stores ONE message and announces it once', function (): void {
    [$groom, $bride] = chatConnected();
    Event::fake([MessageSent::class, ConversationUpdated::class]);
    $clientId = (string) Str::uuid();

    $first = chatSend($groom, $bride, 'Hello', $clientId);
    $again = chatSend($groom, $bride, 'Hello', $clientId);

    expect($again->id)->toBe($first->id)
        ->and(Message::query()->where('type', MessageType::Text->value)->count())->toBe(1)
        ->and(ConversationParticipant::query()->where('profile_id', $bride->profile->id)->value('unread_count'))->toBe(1);
    Event::assertDispatchedTimes(MessageSent::class, 1);
});

it('§9.7.3: a client id the OTHER member already used is refused, not returned', function (): void {
    [$groom, $bride] = chatConnected(PlanCode::Gold, PlanCode::Gold);
    $clientId = (string) Str::uuid();
    chatSend($bride, $groom, 'Hi', $clientId);

    expect(fn () => chatSend($groom, $bride, 'Hi back', $clientId))->toThrow(ValidationException::class);
    expect(Message::query()->where('sender_profile_id', $groom->profile->id)->count())->toBe(0);
});

it('R-M07-4: an empty, whitespace-only or too long message, or a malformed client id, is refused and nothing is written', function (string $body, string $clientId): void {
    [$groom, $bride] = chatConnected();

    expect(fn () => chatSend($groom, $bride, $body, $clientId))->toThrow(ValidationException::class);
    expect(Message::query()->where('type', MessageType::Text->value)->count())->toBe(0);
})->with([
    'empty' => ['', '7d5e2a8e-2f4b-4c8a-9a51-0d6b1f1e9c11'],
    'spaces' => ["   \n  ", '7d5e2a8e-2f4b-4c8a-9a51-0d6b1f1e9c11'],
    'too long' => [str_repeat('a', 2001), '7d5e2a8e-2f4b-4c8a-9a51-0d6b1f1e9c11'],
    'bad client id' => ['Hello', 'not-a-uuid'],
]);

it('R-M07-4: exactly chat.max_length characters is fine, and the limit is an A15 setting', function (): void {
    [$groom, $bride] = chatConnected();

    expect(chatSend($groom, $bride, str_repeat('a', 2000))->body)->toHaveLength(2000);

    Setting::factory()->keyed(SettingKey::ChatMaxLength, 100)->create();
    Settings::flush();
    expect(fn () => chatSend($groom, $bride, str_repeat('a', 101)))->toThrow(ValidationException::class);
});

it('R-M07-3: a Free member can\'t start the conversation, may send ONE reply once the other member wrote, then must upgrade (403)', function (): void {
    [$groom, $bride] = chatConnected(PlanCode::Gold, null);   // the bride is Free
    $access = app(ChatAccess::class);
    $thread = $access->threadWith($bride->profile, $groom->profile);

    expect($access->sendState($bride->profile, $groom->profile, $thread))->toBe(ChatSendState::WaitForReply);
    $refused = sendFails(fn () => chatSend($bride, $groom, 'Hello first'));
    expect($refused)->toBeInstanceOf(ChatRefused::class)
        ->and($refused->getStatusCode())->toBe(403)
        ->and($refused->reason)->toBe('wait_for_reply');

    chatSend($groom, $bride, 'Hello Anna');
    expect($access->sendState($bride->profile, $groom->profile, $thread))->toBe(ChatSendState::CanSend)
        ->and($access->freeRepliesLeft($bride->profile, $groom->profile, $thread))->toBe(1);

    chatSend($bride, $groom, 'Thank you, hello');
    expect($access->sendState($bride->profile, $groom->profile, $thread))->toBe(ChatSendState::Upgrade);

    $refused = sendFails(fn () => chatSend($bride, $groom, 'One more'));
    expect($refused)->toBeInstanceOf(ChatRefused::class)
        ->and($refused->getStatusCode())->toBe(403)
        ->and($refused->reason)->toBe('upgrade')
        ->and(Message::query()->where('sender_profile_id', $bride->profile->id)->count())->toBe(1);
});

it('R-M07-2: two Free members who connected can read but neither can write until one upgrades', function (): void {
    [$groom, $bride] = chatConnected(null, null);

    expect(sendFails(fn () => chatSend($groom, $bride)))->toBeInstanceOf(ChatRefused::class)
        ->and(sendFails(fn () => chatSend($bride, $groom)))->toBeInstanceOf(ChatRefused::class);

    chatOnPlan($groom, PlanCode::Silver);
    expect(chatSend($groom, $bride)->exists)->toBeTrue();
});

it('R-M07-3: the number of free replies comes from the plan (plan_features), not from code', function (): void {
    [$groom, $bride] = chatConnected(PlanCode::Gold, null);
    App\Models\PlanFeature::query()->where('entitlement', 'free_replies_per_conversation')
        ->whereHas('plan', fn ($q) => $q->where('code', PlanCode::Free->value))->update(['limit_value' => 2]);
    app(App\Services\Entitlements\EntitlementService::class)->forget($bride->profile);

    chatSend($groom, $bride);
    chatSend($bride, $groom, 'One');
    chatSend($bride, $groom, 'Two');

    expect(sendFails(fn () => chatSend($bride, $groom, 'Three')))->toBeInstanceOf(ChatRefused::class);
});

it('rate limit: more than chat.max_per_minute messages in a minute is refused (429) and not stored', function (): void {
    Setting::factory()->keyed(SettingKey::ChatMaxPerMinute, 5)->create();
    Settings::flush();
    [$groom, $bride] = chatConnected();

    foreach (range(1, 5) as $i) {
        chatSend($groom, $bride, "Message {$i}");
    }

    $refused = sendFails(fn () => chatSend($groom, $bride, 'Message 6'));
    expect($refused)->toBeInstanceOf(ChatRefused::class)
        ->and($refused->getStatusCode())->toBe(429)
        ->and($refused->reason)->toBe(ChatRefused::TOO_FAST)
        ->and(Message::query()->where('sender_profile_id', $groom->profile->id)->count())->toBe(5);
});

it('a retry of a message already stored does not count against the rate limit', function (): void {
    Setting::factory()->keyed(SettingKey::ChatMaxPerMinute, 5)->create();
    Settings::flush();
    [$groom, $bride] = chatConnected();
    $clientId = (string) Str::uuid();

    foreach (range(1, 8) as $i) {
        chatSend($groom, $bride, 'Same', $clientId);
    }

    expect(chatSend($groom, $bride, 'Another')->exists)->toBeTrue();
});

it('security: a member who is not a participant gets 404 and nothing is written (IDOR)', function (): void {
    [$groom, $bride] = chatConnected();
    $stranger = chatOnPlan(groom('+919800000077'), PlanCode::Diamond);

    expect(fn () => chatSend($stranger, $bride))->toThrow(NotFoundHttpException::class)
        ->and(fn () => app(App\Actions\Chat\SendMessage::class)->handle($stranger, $groom->profile, 'Hi', (string) Str::uuid()))->toThrow(NotFoundHttpException::class);

    expect(Message::query()->where('type', MessageType::Text->value)->count())->toBe(0);
});

it('security: a profile the member never connected with, or their own profile, has no thread (404)', function (): void {
    [$groom] = chatConnected();
    $unconnected = bride('+919800000066');

    expect(fn () => chatSend($groom, $unconnected))->toThrow(NotFoundHttpException::class)
        ->and(fn () => chatSend($groom, $groom))->toThrow(NotFoundHttpException::class);
});

it('R-M03-1 / §9.4: a blocked pair (either direction) gets 404, nothing is written or announced', function (string $who): void {
    [$groom, $bride] = chatConnected(PlanCode::Gold, PlanCode::Gold);
    Event::fake([MessageSent::class, ConversationUpdated::class]);
    [$blocker, $blocked] = $who === 'sender' ? [$groom, $bride] : [$bride, $groom];
    Block::factory()->create(['blocker_profile_id' => $blocker->profile->id, 'blocked_profile_id' => $blocked->profile->id]);

    expect(fn () => chatSend($groom, $bride))->toThrow(NotFoundHttpException::class);
    expect(Message::query()->where('type', MessageType::Text->value)->count())->toBe(0);
    Event::assertNotDispatched(MessageSent::class);
})->with(['sender blocked them' => 'sender', 'they blocked the sender' => 'recipient']);

it('R-M07-5: a FROZEN thread is read-only (409), a CLOSED one does not exist (404)', function (): void {
    [$groom, $bride, $thread] = chatConnected();

    $thread->forceFill(['status' => ConversationStatus::Frozen])->save();
    $refused = sendFails(fn () => chatSend($groom, $bride));
    expect($refused)->toBeInstanceOf(ChatRefused::class)->and($refused->getStatusCode())->toBe(409)->and($refused->reason)->toBe('frozen');

    $thread->forceFill(['status' => ConversationStatus::Closed])->save();
    expect(fn () => chatSend($groom, $bride))->toThrow(NotFoundHttpException::class);
    expect(Message::query()->where('type', MessageType::Text->value)->count())->toBe(0);
});

it('the other member deleted or suspended: the thread stays readable but nobody can write (409)', function (string $how): void {
    [$groom, $bride] = chatConnected();
    $how === 'deleted' ? $bride->profile->delete() : $bride->profile->forceFill(['status' => ProfileStatus::Suspended])->save();
    $bridesProfile = Profile::withTrashed()->whereKey($bride->profile->id)->firstOrFail();

    $refused = sendFails(fn () => app(App\Actions\Chat\SendMessage::class)->handle($groom, $bridesProfile, 'Hi', (string) Str::uuid()));
    expect($refused)->toBeInstanceOf(ChatRefused::class)->and($refused->reason)->toBe('unavailable');
})->with(['deleted', 'suspended']);

it('R-M07-1: once the pair is no longer connected by an accepted interest, nobody can write', function (): void {
    [$groom, $bride] = chatConnected();
    App\Models\Interest::query()->delete();
    app(App\Domain\Engagement\Connections::class)->forget();

    expect(sendFails(fn () => chatSend($groom, $bride)))->toBeInstanceOf(ChatRefused::class);
});

it('security: a suspended member can\'t send (404)', function (): void {
    [$groom, $bride] = chatConnected();
    $groom->forceFill(['status' => UserStatus::Suspended])->save();

    expect(fn () => chatSend($groom->refresh(), $bride))->toThrow(NotFoundHttpException::class);
});

it('P1.7b: an admin support session can\'t send as the member', function (): void {
    [$groom, $bride] = chatConnected();
    chatImpersonate($groom);

    expect(fn () => chatSend($groom, $bride))->toThrow(ImpersonationRestricted::class);
    expect(Message::query()->where('type', MessageType::Text->value)->count())->toBe(0);
});

it('a new message brings an archived thread back to the recipient\'s inbox', function (): void {
    [$groom, $bride, $thread] = chatConnected();
    ConversationParticipant::query()->where('conversation_id', $thread->id)->update(['is_archived' => true]);

    chatSend($groom, $bride);

    expect(ConversationParticipant::query()->where('profile_id', $bride->profile->id)->value('is_archived'))->toBeFalse()
        ->and(ConversationParticipant::query()->where('profile_id', $groom->profile->id)->value('is_archived'))->toBeTrue();
});

it('§9.4: MessageSent is suppressed inside a blocked pair even if it was already queued', function (): void {
    [$groom, $bride, $thread] = chatConnected();
    $event = new MessageSent($thread->id, (string) Str::ulid(), (string) Str::uuid(), $groom->profile->id, $bride->profile->id,
        $groom->profile->code, 'TEXT', 'Hi', now()->toIso8601String());
    expect($event->broadcastWhen())->toBeTrue();

    Block::factory()->create(['blocker_profile_id' => $bride->profile->id, 'blocked_profile_id' => $groom->profile->id]);
    expect($event->broadcastWhen())->toBeFalse();
});
