<?php

declare(strict_types=1);

use App\Actions\Chat\MarkConversationRead;
use App\Actions\Chat\MarkMessagesDelivered;
use App\Actions\Chat\SendIcebreaker;
use App\Actions\Chat\SendMessage;
use App\Actions\Chat\SetConversationMuted;
use App\Actions\Chat\UnsendMessage;
use App\Domain\Chat\LastSeen;
use App\Enums\MessageType;
use App\Enums\PlanCode;
use App\Enums\SettingKey;
use App\Events\Chat\ConversationUpdated;
use App\Events\Chat\MessageDeleted;
use App\Events\Chat\MessagesDelivered;
use App\Events\Chat\MessageSent;
use App\Events\Chat\MessagesRead;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Chat\ChatRefused;
use App\Jobs\Chat\PruneChatHistory;
use App\Jobs\Chat\SendChatDigests;
use App\Models\Block;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Media;
use App\Models\Message;
use App\Models\PrivacySetting;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\Chat\NewMessagesDigest;
use App\Queries\Chat\InboxList;
use App\Queries\Chat\ThreadWindow;
use App\Support\Facades\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\PlansSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
| P4.2 — chat part 2 (PRD M07, R-M07-4..7, §9.6, §9.7): unsend, image messages and their private
| route, icebreakers, mute, load earlier (id cursor), last seen, the offline digest, retention, and
| the P4.1 review fixes (read receipts both ways, grouped delivery).
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
    fakeMediaDisks();
});

function chatRefusal(callable $call): Throwable
{
    try {
        $call();
    } catch (Throwable $e) {
        return $e;
    }

    throw new RuntimeException('Expected a refusal.');
}

function chatImage(User $from, User $to, ?UploadedFile $file = null, ?string $clientId = null): Message
{
    return app(SendMessage::class)->image($from, $to->profile, $file ?? gradientPhoto(), $clientId ?? (string) Str::uuid());
}

// ---- Unsend -------------------------------------------------------------------------------------

it('unsend: the sender, within the window, leaves a tombstone everyone sees as "This message was deleted", announced live', function (): void {
    [$groom, $bride, $thread] = chatConnected();
    $message = chatSend($groom, $bride, 'Oops, wrong words');
    Event::fake([MessageDeleted::class, ConversationUpdated::class]);

    app(UnsendMessage::class)->handle($groom, $message->id);

    $message->refresh();
    expect($message->deleted_for_all_at)->not->toBeNull()
        ->and($message->body)->toBeNull()
        ->and(Message::query()->whereKey($message->id)->exists())->toBeTrue();
    Event::assertDispatched(MessageDeleted::class, fn (MessageDeleted $e): bool => $e->broadcastOn()[0]->name === 'private-chat.'.$thread->id
        && $e->broadcastAs() === 'message.deleted' && $e->broadcastWith() === ['id' => $message->id]);

    $inbox = app(InboxList::class)->page($bride, $bride->profile, InboxList::ALL, '', 20)['items'];
    expect($inbox[0]->preview)->toBe('This message was deleted');
    $seen = collect(app(ThreadWindow::class)->window($thread, $bride->profile, true)['messages'])->firstWhere('id', $message->id);
    expect($seen->text)->toBe('This message was deleted')->and($seen->deleted)->toBeTrue();
});

it('unsend: refused after chat.unsend_window_minutes, a second time, and for a SYSTEM message', function (): void {
    [$groom, $bride, $thread] = chatConnected();
    $message = chatSend($groom, $bride);

    $this->travel(61)->minutes();
    expect(chatRefusal(fn () => app(UnsendMessage::class)->handle($groom, $message->id)))->toBeInstanceOf(ChatRefused::class);

    Setting::factory()->keyed(SettingKey::ChatUnsendWindowMinutes, 120)->create();
    Settings::flush();
    app(UnsendMessage::class)->handle($groom, $message->id);
    expect(chatRefusal(fn () => app(UnsendMessage::class)->handle($groom, $message->id)))->toBeInstanceOf(ChatRefused::class);

    $system = Message::query()->where('conversation_id', $thread->id)->where('type', MessageType::System->value)->sole();
    expect(fn () => app(UnsendMessage::class)->handle($groom, $system->id))->toThrow(NotFoundHttpException::class);
});

it('unsend security: someone else\'s message, a made-up id, a blocked pair → 404; a support session → refused', function (): void {
    [$groom, $bride] = chatConnected(PlanCode::Gold, PlanCode::Gold);
    $his = chatSend($groom, $bride);
    $hers = chatSend($bride, $groom);

    expect(fn () => app(UnsendMessage::class)->handle($groom, $hers->id))->toThrow(NotFoundHttpException::class)
        ->and(fn () => app(UnsendMessage::class)->handle($groom, 'not-a-ulid'))->toThrow(NotFoundHttpException::class)
        ->and(fn () => app(UnsendMessage::class)->handle($groom, (string) Str::ulid()))->toThrow(NotFoundHttpException::class);

    chatImpersonate($groom);
    expect(fn () => app(UnsendMessage::class)->handle($groom, $his->id))->toThrow(ImpersonationRestricted::class);
    session()->flush();
    app(App\Services\Admin\Impersonation::class)->forget();

    Block::factory()->create(['blocker_profile_id' => $bride->profile->id, 'blocked_profile_id' => $groom->profile->id]);
    expect(fn () => app(UnsendMessage::class)->handle($groom, $his->id))->toThrow(NotFoundHttpException::class);
    expect($his->refresh()->deleted_for_all_at)->toBeNull();
});

// ---- Image messages -----------------------------------------------------------------------------

it('M07 images: a paid member\'s photo is re-encoded and stored privately; the live payload carries no file', function (): void {
    [$groom, $bride, $thread] = chatConnected();
    Event::fake([MessageSent::class, ConversationUpdated::class]);

    $message = chatImage($groom, $bride);

    $media = $message->getFirstMedia(Message::IMAGE);
    expect($message->type)->toBe(MessageType::Image)
        ->and($message->body)->toBeNull()
        ->and($media)->not->toBeNull()
        ->and($media->disk)->toBe(config('oppam.media.private_disk'))
        ->and($media->mime_type)->toBe('image/jpeg');
    Event::assertDispatched(MessageSent::class, fn (MessageSent $e): bool => $e->broadcastWith()['type'] === 'IMAGE' && $e->broadcastWith()['body'] === ''
        && ! str_contains((string) json_encode($e->broadcastWith()), 'storage'));
    expect(app(InboxList::class)->page($bride, $bride->profile, InboxList::ALL, '', 20)['items'][0]->preview)->toBe('📷 Photo');
});

it('M07 images: a Free member can\'t send one, even when a free reply is left (403)', function (): void {
    [$groom, $bride] = chatConnected(PlanCode::Gold, null);
    chatSend($groom, $bride, 'Hello');

    $refused = chatRefusal(fn () => chatImage($bride, $groom));
    expect($refused)->toBeInstanceOf(ChatRefused::class)
        ->and($refused->getStatusCode())->toBe(403)
        ->and($refused->reason)->toBe(ChatRefused::IMAGE_PLAN)
        ->and(Message::query()->where('type', MessageType::Image->value)->count())->toBe(0);
});

it('M07 images: not an image (a renamed script), a wrong type or over chat.image_max_mb is refused, nothing stored', function (string $case): void {
    [$groom, $bride] = chatConnected();
    $file = match ($case) {
        'renamed script' => UploadedFile::fake()->createWithContent('photo.jpg', '<?php echo "x";'),
        'pdf' => UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf'),
        'too big' => UploadedFile::fake()->image('big.jpg')->size(6 * 1024),
        'too many pixels' => UploadedFile::fake()->image('wide.png', 8001, 10),
    };

    expect(fn () => chatImage($groom, $bride, $file))->toThrow(ValidationException::class);
    expect(Message::query()->where('type', MessageType::Image->value)->count())->toBe(0)
        ->and(Media::query()->where('model_type', (new Message)->getMorphClass())->count())->toBe(0);
})->with(['renamed script', 'pdf', 'too big', 'too many pixels']);

it('M07 images: over chat.max_per_minute is refused before the photo is processed, nothing stored', function (): void {
    [$groom, $bride] = chatConnected();
    $max = Settings::int(SettingKey::ChatMaxPerMinute);
    foreach (range(1, $max) as $ignored) {
        Illuminate\Support\Facades\RateLimiter::hit('chat-send:'.$groom->profile->id, 60);
    }

    expect(fn () => chatImage($groom, $bride))->toThrow(ChatRefused::class)
        ->and(Message::query()->where('type', MessageType::Image->value)->count())->toBe(0);
});

it('M07 images: the same client id twice stores one image', function (): void {
    [$groom, $bride] = chatConnected();
    $clientId = (string) Str::uuid();

    $first = chatImage($groom, $bride, null, $clientId);
    $again = chatImage($groom, $bride, null, $clientId);

    expect($again->id)->toBe($first->id)
        ->and(Media::query()->where('model_type', (new Message)->getMorphClass())->count())->toBe(1);
});

it('security: the image route serves a participant only — never the other members, a blocked pair, a guest or a support session', function (): void {
    [$groom, $bride] = chatConnected();
    $message = chatImage($groom, $bride);
    $url = route('member.chat.image', ['message' => $message->id, 'size' => 'original']);
    $stranger = bride('+919800000077');

    $this->actingAs($bride, 'web')->get($url)->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')->assertHeader('Cache-Control', 'no-store, private');
    $this->actingAs($groom, 'web')->get(route('member.chat.image', ['message' => $message->id, 'size' => 'preview']))->assertOk();
    $this->actingAs($stranger, 'web')->get($url)->assertNotFound();

    // A support session (the controller itself: the HTTP support-session middleware needs a real admin hand-over).
    chatImpersonate($bride);
    $this->actingAs($bride, 'web');
    expect(fn () => app()->call(App\Http\Controllers\Member\ChatImageController::class, ['request' => request(), 'message' => $message->id, 'size' => 'original']))
        ->toThrow(NotFoundHttpException::class);
    session()->flush();
    app(App\Services\Admin\Impersonation::class)->forget();

    Block::factory()->create(['blocker_profile_id' => $groom->profile->id, 'blocked_profile_id' => $bride->profile->id]);
    $this->actingAs($bride, 'web')->get($url)->assertNotFound();

    auth('web')->logout();
    $this->get($url)->assertRedirectContains('/login');
});

it('the image route: a text message or an unsent image is 404; the file is deleted on unsend', function (): void {
    [$groom, $bride] = chatConnected();
    $text = chatSend($groom, $bride);
    $image = chatImage($groom, $bride);

    $this->actingAs($bride, 'web')->get(route('member.chat.image', ['message' => $text->id, 'size' => 'original']))->assertNotFound();

    app(UnsendMessage::class)->handle($groom, $image->id);
    expect(Media::query()->where('model_type', (new Message)->getMorphClass())->count())->toBe(0);
    $this->actingAs($bride, 'web')->get(route('member.chat.image', ['message' => $image->id, 'size' => 'original']))->assertNotFound();
});

// ---- Icebreakers --------------------------------------------------------------------------------

it('icebreakers: one of the A11 list as the FIRST message; never a second time, never an unknown code', function (): void {
    [$groom, $bride] = chatConnected();
    $icebreakers = app(SendIcebreaker::class);
    $options = $icebreakers->options();
    expect($options)->toHaveKey('I01');

    $message = $icebreakers->handle($groom, $bride->profile, 'I01', (string) Str::uuid());
    expect($message->body)->toBe($options['I01']);

    expect(chatRefusal(fn () => $icebreakers->handle($groom, $bride->profile, 'I02', (string) Str::uuid())))->toBeInstanceOf(ChatRefused::class);

    [$groom2, $bride2] = [chatOnPlan(groom('+919800000088'), PlanCode::Gold), bride('+919800000087')];
    app(App\Actions\Engagement\SendInterest::class)->handle($groom2, $bride2->profile, 'T01');
    app(App\Actions\Engagement\AcceptInterest::class)->handle($bride2, $groom2->profile);
    expect(chatRefusal(fn () => $icebreakers->handle($groom2, $bride2->profile, 'NOPE', (string) Str::uuid())))->toBeInstanceOf(ChatRefused::class);
});

it('icebreakers follow the plan rules: a Free member can\'t start, but may use one as the one reply (R-M07-3)', function (): void {
    [$groom, $bride] = chatConnected(PlanCode::Gold, null);
    $icebreakers = app(SendIcebreaker::class);

    expect(chatRefusal(fn () => $icebreakers->handle($bride, $groom->profile, 'I01', (string) Str::uuid())))->toBeInstanceOf(ChatRefused::class);

    chatSend($groom, $bride, 'Hello');
    expect($icebreakers->handle($bride, $groom->profile, 'I01', (string) Str::uuid())->exists)->toBeTrue();
});

// ---- Mute ---------------------------------------------------------------------------------------

it('mute: only the member\'s own side; the inbox signal says muted (no toast); a support session can\'t', function (): void {
    [$groom, $bride] = chatConnected();
    app(SetConversationMuted::class)->handle($bride, $groom->profile, true);

    expect(ConversationParticipant::query()->where('profile_id', $bride->profile->id)->value('is_muted'))->toBeTrue()
        ->and(ConversationParticipant::query()->where('profile_id', $groom->profile->id)->value('is_muted'))->toBeFalse();

    Event::fake([ConversationUpdated::class]);
    chatSend($groom, $bride);
    Event::assertDispatched(ConversationUpdated::class, fn (ConversationUpdated $e): bool => $e->profileId === $bride->profile->id && $e->muted);

    chatImpersonate($bride);
    expect(fn () => app(SetConversationMuted::class)->handle($bride, $groom->profile, false))->toThrow(ImpersonationRestricted::class);
});

it('mute: someone else\'s thread is 404', function (): void {
    [$groom] = chatConnected();

    expect(fn () => app(SetConversationMuted::class)->handle(bride('+919800000066'), $groom->profile, true))->toThrow(NotFoundHttpException::class);
});

// ---- Load earlier (cursor) ----------------------------------------------------------------------

it('load earlier: the newest 30 first, then the cursor moves back 30 at a time, in order, until the start', function (): void {
    [$groom, $bride, $thread] = chatConnected();
    Setting::factory()->keyed(SettingKey::ChatMaxPerMinute, 300)->create();
    Settings::flush();
    foreach (range(1, 70) as $i) {
        chatSend($groom, $bride, "Message {$i}");
    }
    $window = app(ThreadWindow::class);

    $first = $window->window($thread, $bride->profile, true);
    expect($first['messages'])->toHaveCount(30)
        ->and($first['messages'][0]->text)->toBe('Message 41')
        ->and(end($first['messages'])->text)->toBe('Message 70')
        ->and($first['hasEarlier'])->toBeTrue();

    $cursor = $window->earlierCursor($thread, $first['oldestId']);
    $second = $window->window($thread, $bride->profile, true, $cursor);
    expect($second['messages'])->toHaveCount(60)->and($second['messages'][0]->text)->toBe('Message 11');

    $cursor = $window->earlierCursor($thread, $second['oldestId']);
    $all = $window->window($thread, $bride->profile, true, $cursor);
    expect($all['messages'])->toHaveCount(71)   // + "You are now connected"
        ->and($all['messages'][0]->system)->toBeTrue()
        ->and($all['hasEarlier'])->toBeFalse()
        ->and(collect($all['messages'])->pluck('id')->all())->toBe(collect($all['messages'])->pluck('id')->sort()->values()->all());
});

it('§9.7.1 back-fill: a window from the cursor includes every message sent since, in order', function (): void {
    [$groom, $bride, $thread] = chatConnected();
    $before = app(ThreadWindow::class)->window($thread, $bride->profile, true);

    chatSend($groom, $bride, 'Missed one');
    chatSend($groom, $bride, 'Missed two');

    $after = app(ThreadWindow::class)->window($thread, $bride->profile, true, $before['oldestId']);
    expect(collect($after['messages'])->pluck('text')->slice(-2)->values()->all())->toBe(['Missed one', 'Missed two']);
});

// ---- Last seen ----------------------------------------------------------------------------------

it('§9.6 last seen: shown in IST words, hidden when the member turned it off', function (): void {
    [$groom] = chatConnected();
    $now = CarbonImmutable::parse('2026-10-16 18:00', 'Asia/Kolkata');
    $groom->forceFill(['last_seen_at' => $now->subMinutes(30)->utc()])->save();
    $lastSeen = app(LastSeen::class);

    expect($lastSeen->label($groom->profile, $now))->toBe('Last seen today at 17:30');

    $groom->forceFill(['last_seen_at' => $now->subDay()->utc()])->save();
    expect($lastSeen->label($groom->profile, $now))->toBe('Last seen yesterday at 18:00');

    (PrivacySetting::query()->whereKey($groom->profile->id)->first() ?? (new PrivacySetting)->forceFill(['profile_id' => $groom->profile->id]))
        ->forceFill(['show_last_seen' => false])->save();
    expect($lastSeen->label($groom->profile, $now))->toBeNull();
});

// ---- Offline digest -----------------------------------------------------------------------------

function digestReady(User $member): User
{
    $member->forceFill(['email' => 'member'.$member->id.'@example.test', 'email_verified_at' => now(), 'last_seen_at' => now()->subHour()])->save();

    return $member->refresh();
}

it('M07 / M08 digest: an offline member with unread messages gets ONE email (names and counts, no text) after 10 minutes', function (): void {
    [$groom, $bride] = chatConnected();
    $bride = digestReady($bride);
    chatSend($groom, $bride, 'Secret words one');
    chatSend($groom, $bride, 'Secret words two');

    (new SendChatDigests)->handle();
    Notification::assertNotSentTo($bride, NewMessagesDigest::class);   // not 10 minutes yet

    $this->travel(11)->minutes();
    (new SendChatDigests)->handle();
    Notification::assertSentTo($bride, NewMessagesDigest::class, function (NewMessagesDigest $n, array $channels) use ($groom, $bride): bool {
        $mail = $n->toMail($bride);

        return $channels === ['mail'] && $n->threads === [['name' => $groom->profile->first_name, 'count' => 2]]
            && ! str_contains((string) json_encode($mail->toArray()), 'Secret words');
    });

    (new SendChatDigests)->handle();
    Notification::assertSentToTimes($bride, NewMessagesDigest::class, 1);   // each message announced once
});

it('M07 digest: nobody online, muted, blocked, already read — and no email without a verified address', function (string $case): void {
    [$groom, $bride] = chatConnected();
    $bride = digestReady($bride);
    chatSend($groom, $bride, 'Hello');
    $this->travel(11)->minutes();

    match ($case) {
        'online' => $bride->forceFill(['last_seen_at' => now()->subMinutes(2)])->save(),
        'muted' => app(SetConversationMuted::class)->handle($bride, $groom->profile, true),
        'blocked' => Block::factory()->create(['blocker_profile_id' => $bride->profile->id, 'blocked_profile_id' => $groom->profile->id]),
        'read' => app(MarkConversationRead::class)->handle($bride, $groom->profile),
        'unverified email' => $bride->forceFill(['email_verified_at' => null])->save(),
    };

    (new SendChatDigests)->handle();
    Notification::assertNotSentTo($bride, NewMessagesDigest::class, fn (NewMessagesDigest $n, array $channels): bool => $channels !== []);
})->with(['online', 'muted', 'blocked', 'read', 'unverified email']);

it('M07 digest: a later message gets its own digest; threads already announced are not re-read (fixed query count)', function (): void {
    $pairs = collect(range(1, 3))->map(function (int $i): array {
        [$groom, $bride] = [groom('+91980000010'.$i), bride('+91980000020'.$i)];
        app(App\Actions\Engagement\SendInterest::class)->handle($groom, $bride->profile, 'T01');
        app(App\Actions\Engagement\AcceptInterest::class)->handle($bride, $groom->profile);
        chatOnPlan($groom, PlanCode::Gold);

        return [$groom, $bride];
    });
    $pairs->each(function (array $pair): void {
        digestReady($pair[1]);
        chatSend($pair[0], $pair[1], 'Hello');
    });
    $this->travel(11)->minutes();
    (new SendChatDigests)->handle();
    $pairs->each(fn (array $pair) => Notification::assertSentToTimes($pair[1]->refresh(), NewMessagesDigest::class, 1));

    // Nothing new: the members still have unread messages, but the run reads no thread or message.
    DB::flushQueryLog();
    DB::enableQueryLog();
    (new SendChatDigests)->handle();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();
    expect($queries->filter(fn (string $sql): bool => str_contains($sql, 'from `messages`') || str_contains($sql, 'join `messages`')))->toBeEmpty()
        ->and($queries)->toHaveCount(1);

    // A new message to one of them → one more digest, for that member only.
    [$groom, $bride] = $pairs->first();
    chatSend($groom, $bride, 'Are you there?');
    $this->travel(11)->minutes();
    (new SendChatDigests)->handle();
    Notification::assertSentToTimes($bride->refresh(), NewMessagesDigest::class, 2);
    Notification::assertSentToTimes($pairs->last()[1]->refresh(), NewMessagesDigest::class, 1);
});

it('the digest and the retention job are scheduled', function (): void {
    $jobs = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($e): string => (string) ($e->description ?? ''));

    expect($jobs->contains(fn (string $d): bool => str_contains($d, 'SendChatDigests')))->toBeTrue()
        ->and($jobs->contains(fn (string $d): bool => str_contains($d, 'PruneChatHistory')))->toBeTrue();
});

// ---- Retention ----------------------------------------------------------------------------------

it('R-M07-6: a thread with no activity for chat.retention_days goes with its messages and image files; newer ones stay', function (): void {
    [$groom, $bride, $old] = chatConnected();
    chatImage($groom, $bride);
    $old->forceFill(['last_message_at' => now()->subDays(731)])->save();

    $groom2 = chatOnPlan(groom('+919800000044'), PlanCode::Gold);
    $bride2 = bride('+919800000043');
    app(App\Actions\Engagement\SendInterest::class)->handle($groom2, $bride2->profile, 'T01');
    $recent = app(App\Actions\Engagement\AcceptInterest::class)->handle($bride2, $groom2->profile);

    (new PruneChatHistory)->handle();

    expect(Conversation::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(Message::query()->where('conversation_id', $old->id)->count())->toBe(0)
        ->and(Media::query()->where('model_type', (new Message)->getMorphClass())->count())->toBe(0)
        ->and(Conversation::query()->whereKey($recent->id)->exists())->toBeTrue();
});

// ---- P4.1 review fixes --------------------------------------------------------------------------

it('R-M07-7 both ways: a SENDER with read receipts off is never sent the reader\'s read time', function (): void {
    [$groom, $bride] = chatConnected();
    (PrivacySetting::query()->whereKey($groom->profile->id)->first() ?? (new PrivacySetting)->forceFill(['profile_id' => $groom->profile->id]))
        ->forceFill(['read_receipts' => false])->save();
    chatSend($groom, $bride);

    Event::fake([MessagesRead::class, MessagesDelivered::class, ConversationUpdated::class]);
    app(MarkConversationRead::class)->handle($bride, $groom->profile);

    Event::assertNotDispatched(MessagesRead::class);
    Event::assertDispatched(MessagesDelivered::class);
});

it('delivered: one signal per thread with the newest message id, from grouped queries', function (): void {
    [$groom, $bride, $thread] = chatConnected();
    $groom2 = chatOnPlan(groom('+919800000033'), PlanCode::Gold);
    app(App\Actions\Engagement\SendInterest::class)->handle($groom2, $bride->profile, 'T01');
    $thread2 = app(App\Actions\Engagement\AcceptInterest::class)->handle($bride, $groom2->profile);
    chatSend($groom, $bride, 'a');
    $last1 = chatSend($groom, $bride, 'b');
    $last2 = chatSend($groom2, $bride, 'c');

    Event::fake([MessagesDelivered::class]);
    expect(app(MarkMessagesDelivered::class)->handle($bride->profile))->toBe(3);

    Event::assertDispatchedTimes(MessagesDelivered::class, 2);
    Event::assertDispatched(MessagesDelivered::class, fn (MessagesDelivered $e): bool => $e->conversationId === $thread->id && $e->upToMessageId === $last1->id);
    Event::assertDispatched(MessagesDelivered::class, fn (MessagesDelivered $e): bool => $e->conversationId === $thread2->id && $e->upToMessageId === $last2->id);
});

it('A15: the P4.2 chat settings have the PRD defaults', function (): void {
    expect(SettingKey::ChatImageMaxMb->default())->toBe(5)
        ->and(SettingKey::ChatRetentionDays->default())->toBe(730)
        ->and(SettingKey::ChatDigestDelayMinutes->default())->toBe(10)
        ->and(SettingKey::ChatUnsendWindowMinutes->default())->toBe(60);
});
