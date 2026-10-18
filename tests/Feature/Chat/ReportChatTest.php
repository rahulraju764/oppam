<?php

declare(strict_types=1);

use App\Actions\Chat\ReportChatMessages;
use App\Enums\ConversationStatus;
use App\Enums\FreezeReason;
use App\Enums\MessageFlagRule;
use App\Enums\MessageFlagSource;
use App\Enums\PlanCode;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Events\Admin\AdminQueueCountChanged;
use App\Events\Chat\ConversationFrozen;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Safety\ReportRefused;
use App\Models\AuditLog;
use App\Models\Block;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageFlag;
use App\Models\Report;
use App\Models\User;
use App\Support\Facades\Settings;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
| P4.3a — report from chat (PRD M09 "message selection auto-attached", A13 "user-reported
| messages"): the security matrix, and the automatic freeze when 3+ different members report the
| same sender (decisions 2026-10-16). The reported member is never told.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
});

/** @param  list<string>|null  $ids */
function chatReport(User $reporter, User $reported, ?array $ids = null, string $reason = 'ABUSIVE_MESSAGES', ?string $details = null): Report
{
    // Default: everything the reported member wrote in THIS pair's thread.
    $thread = Conversation::query()->where('pair_key', Conversation::pairKey($reporter->profile->id, $reported->profile->id))->value('id');
    $ids ??= Message::query()->where('conversation_id', $thread)->where('sender_profile_id', $reported->profile->id)->pluck('id')->all();

    return app(ReportChatMessages::class)->handle($reporter, $reported->profile, $reason, $details, $ids);
}

it('M09: the bride reports the groom with two of his messages — one report, two REPORT flags in the A13 queue, nothing frozen, nobody told', function (): void {
    Event::fake([AdminQueueCountChanged::class, ConversationFrozen::class]);
    [$groom, $bride, $thread] = chatConnected();
    $first = chatSend($groom, $bride, 'You are rude');
    $second = chatSend($groom, $bride, 'Answer me now');
    Notification::fake();   // from here on: the report itself tells nobody

    $report = chatReport($bride, $groom, [$first->id, $second->id], 'ABUSIVE_MESSAGES', 'He keeps insulting me');

    expect($report->reporter_profile_id)->toBe($bride->profile->id)->and($report->reported_profile_id)->toBe($groom->profile->id)
        ->and($report->reason)->toBe(ReportReason::AbusiveMessages)->and($report->status)->toBe(ReportStatus::Open)
        ->and($report->conversation_id)->toBe($thread->id)
        ->and($report->messages()->pluck('messages.id')->sort()->values()->all())->toBe(collect([$first->id, $second->id])->sort()->values()->all());
    $flags = MessageFlag::query()->get();
    expect($flags)->toHaveCount(2)
        ->and($flags->every(fn (MessageFlag $f): bool => $f->source === MessageFlagSource::Report && $f->rule === MessageFlagRule::Reported
            && $f->reporter_profile_id === $bride->profile->id && $f->report_id === $report->id))->toBeTrue()
        ->and($thread->refresh()->status)->toBe(ConversationStatus::Open);

    Event::assertDispatched(AdminQueueCountChanged::class, fn (AdminQueueCountChanged $e): bool => $e->queueKey === MessageFlag::QUEUE_KEY && $e->count === 2);
    Event::assertNotDispatched(ConversationFrozen::class);
    Notification::assertNothingSentTo($groom);
});

it('security: a stranger, a blocked pair, a suspended reporter, a made-up code → 404; a support session is refused; nothing is written', function (string $case): void {
    [$groom, $bride] = chatConnected();
    chatSend($groom, $bride, 'Hello there');
    $ids = Message::query()->where('sender_profile_id', $groom->profile->id)->pluck('id')->all();
    $reporter = $bride;
    $target = $groom->profile;

    match ($case) {
        'stranger' => $reporter = bride('+919800000301'),
        'blocked' => Block::factory()->create(['blocker_profile_id' => $groom->profile->id, 'blocked_profile_id' => $bride->profile->id]),
        'suspended reporter' => $bride->forceFill(['status' => UserStatus::Suspended])->save(),
        'made-up profile' => $target = App\Models\Profile::factory()->create(),
        'support session' => chatImpersonate($bride),
    };

    $expected = $case === 'support session' ? ImpersonationRestricted::class : NotFoundHttpException::class;
    expect(fn () => app(ReportChatMessages::class)->handle($reporter->refresh(), $target, 'SPAM', null, $ids))->toThrow($expected)
        ->and(Report::query()->count())->toBe(0)->and(MessageFlag::query()->count())->toBe(0);
})->with(['stranger', 'blocked', 'suspended reporter', 'made-up profile', 'support session']);

it('security: tampered message ids — my own message, a message of another thread, a SYSTEM or unsent message, a malformed id — are refused, nothing written', function (string $case): void {
    [$groom, $bride, $thread] = chatConnected(PlanCode::Gold, PlanCode::Gold);
    $his = chatSend($groom, $bride, 'Hello there');
    $mine = chatSend($bride, $groom, 'Hi');
    [$other, $third] = chatPairOf(groom('+919800000302'), bride('+919800000303'));
    $elsewhere = chatSend($other, $third, 'Elsewhere');

    $ids = match ($case) {
        'my own message' => [$his->id, $mine->id],
        'another thread' => [$his->id, $elsewhere->id],
        'system message' => [Message::query()->where('conversation_id', $thread->id)->whereNull('sender_profile_id')->value('id')],
        'unsent message' => tap([$his->id], fn () => $his->forceFill(['deleted_for_all_at' => now(), 'body' => null])->save()),
        'malformed id' => ["1' OR '1'='1"],
        'none' => [],
        'too many' => array_fill(0, 11, $his->id),
    };

    expect(fn () => chatReport($bride, $groom, $ids))->toThrow(ValidationException::class)
        ->and(Report::query()->count())->toBe(0)->and(MessageFlag::query()->count())->toBe(0);
})->with(['my own message', 'another thread', 'system message', 'unsent message', 'malformed id', 'none', 'too many']);

it('validation: the reason must be on the M09 list; "something else" needs details; details at most 500', function (string $reason, ?string $details): void {
    [$groom, $bride] = chatConnected();
    chatSend($groom, $bride, 'Hello there');

    expect(fn () => chatReport($bride, $groom, null, $reason, $details))->toThrow(ValidationException::class)
        ->and(Report::query()->count())->toBe(0);
})->with([
    'unknown reason' => ['STATUS', null],
    'other without details' => ['OTHER', '   '],
    'details too long' => ['SPAM', str_repeat('x', 501)],
]);

it('rule 10: over chat.reports_max_per_hour is 429; a second open report on the same member is 409; nothing written', function (): void {
    [$groom, $bride] = chatConnected();
    chatSend($groom, $bride, 'Hello there');
    chatReport($bride, $groom);

    $again = chatRefusalOf(fn () => chatReport($bride, $groom));
    expect($again)->toBeInstanceOf(ReportRefused::class)->and($again->reason)->toBe(ReportRefused::ALREADY_REPORTED)->and($again->getStatusCode())->toBe(409);

    foreach (range(1, Settings::int(SettingKey::ChatReportsMaxPerHour)) as $ignored) {
        RateLimiter::hit('chat-report:'.$bride->profile->id, 3600);
    }
    $limited = chatRefusalOf(fn () => chatReport($bride, $groom));
    expect($limited->reason)->toBe(ReportRefused::TOO_MANY)->and($limited->getStatusCode())->toBe(429)
        ->and(Report::query()->count())->toBe(1);
});

it('M09 auto-freeze: reported from chat by 3 DIFFERENT members within 30 days → all the sender\'s OPEN threads freeze, live, audited', function (): void {
    Event::fake([ConversationFrozen::class, AdminQueueCountChanged::class]);
    $sender = groom();
    $threads = [];
    $reporters = [];
    foreach (['+919800000311', '+919800000312', '+919800000313', '+919800000314'] as $i => $phone) {
        [, $reporter, $thread] = chatPairOf($sender->refresh(), bride($phone), $i === 0 ? PlanCode::Gold : null);
        chatSend($sender->refresh(), $reporter, 'Message number '.$i);
        $threads[] = $thread;
        $reporters[] = $reporter;
    }

    chatReport($reporters[0], $sender);
    chatReport($reporters[1], $sender);
    expect(Conversation::query()->where('status', ConversationStatus::Frozen->value)->count())->toBe(0);   // two is not enough

    chatReport($reporters[2], $sender);
    foreach ($threads as $thread) {
        expect($thread->refresh()->status)->toBe(ConversationStatus::Frozen)->and($thread->frozen_reason)->toBe(FreezeReason::Reports);
    }
    Event::assertDispatchedTimes(ConversationFrozen::class, 4);
    $audit = AuditLog::query()->where('action', 'chat.auto_frozen')->sole();
    expect($audit->after)->toMatchArray(['threads_frozen' => 4, 'reporters' => 3])->and($audit->subject_label)->toBe($sender->profile->code);
});

it('M09 auto-freeze: dismissed reports and reports older than the window don\'t count', function (): void {
    $sender = groom();
    $reporters = [];
    foreach (['+919800000321', '+919800000322', '+919800000323'] as $i => $phone) {
        [, $reporter] = chatPairOf($sender->refresh(), bride($phone), $i === 0 ? PlanCode::Gold : null);
        chatSend($sender->refresh(), $reporter, 'Message number '.$i);
        $reporters[] = $reporter;
    }

    chatReport($reporters[0], $sender)->forceFill(['status' => ReportStatus::Dismissed])->save();
    chatReport($reporters[1], $sender)->forceFill(['created_at' => now()->subDays(31)])->save();
    chatReport($reporters[2], $sender);

    expect(Conversation::query()->where('status', ConversationStatus::Frozen->value)->count())->toBe(0);
});

function chatRefusalOf(callable $call): ReportRefused
{
    try {
        $call();
    } catch (ReportRefused $refused) {
        return $refused;
    }

    throw new RuntimeException('Expected a ReportRefused.');
}
