<?php

declare(strict_types=1);

use App\Broadcasting\ChatChannel;
use App\Broadcasting\InboxChannel;
use App\Broadcasting\PresenceChatChannel;
use App\Events\Chat\ConversationUpdated;
use App\Events\Chat\MessagesDelivered;
use App\Events\Chat\MessagesRead;
use App\Models\Conversation;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Notification;

/*
| P4.1 — chat channels and payloads (PRD §9.3, §9.4, R-M07-6). P3.1 covers participant / blocked /
| closed / suspended. Here: an admin support session may follow neither the member's threads nor
| their inbox (message text is private — owner decision 2026-10-15), and the chat events carry codes
| and display data only.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Notification::fake();
});

it('R-M07-6: an admin support session can\'t subscribe to the member\'s threads or inbox', function (): void {
    [$groom, , $thread] = chatConnected();
    $chat = app(ChatChannel::class);
    $inbox = app(InboxChannel::class);

    expect($chat->join($groom, $thread->id))->toBeTrue()
        ->and($inbox->join($groom, $groom->profile->id))->toBeTrue();

    chatImpersonate($groom);

    expect(app(ChatChannel::class)->join($groom, $thread->id))->toBeFalse()
        ->and(app(PresenceChatChannel::class)->join($groom, $thread->id))->toBeFalse()
        ->and(app(InboxChannel::class)->join($groom, $groom->profile->id))->toBeFalse();
});

it('§9.4: inbox, read and delivered events name the right channel and carry no ids, phone or email', function (): void {
    [$groom, $bride, $thread] = chatConnected();
    $updated = new ConversationUpdated($bride->profile->id, $groom->profile->code, 'Arun', 'Hello', now()->toIso8601String(), 1, 3, false);
    $read = new MessagesRead($thread->id, $bride->profile->code, (string) Str::ulid(), now()->toIso8601String());
    $delivered = new MessagesDelivered($thread->id, (string) Str::ulid());

    expect($updated->broadcastOn()[0]->name)->toBe('private-inbox.'.$bride->profile->id)
        ->and($updated->broadcastAs())->toBe('conversation.updated')
        ->and($updated->queue)->toBe('broadcasts')
        ->and(array_keys($updated->broadcastWith()))->toBe(['with_code', 'name', 'preview', 'at', 'unread', 'total_unread', 'from_me'])
        ->and($read->broadcastAs())->toBe('message.read')
        ->and($delivered->broadcastAs())->toBe('message.delivered');

    foreach ([$updated, $read, $delivered] as $event) {
        $json = (string) json_encode($event->broadcastWith());
        expect($json)->not->toContain($groom->profile->id)->not->toContain($bride->profile->id)
            ->not->toContain((string) $groom->phone)->not->toContain((string) $bride->phone);
    }
});

it('§9.3: the chat channel is the thread\'s ULID; a member of another thread is refused', function (): void {
    [$groom, , $thread] = chatConnected();
    $other = Conversation::factory()->between(bride('+919800000022')->profile, groom('+919800000021')->profile)->create();

    expect(app(ChatChannel::class)->join($groom, $thread->id))->toBeTrue()
        ->and(app(ChatChannel::class)->join($groom, $other->id))->toBeFalse();
});
