<?php

declare(strict_types=1);

use App\Actions\Admin\Chat\DecideMessageFlag;
use App\Actions\Admin\Chat\RevealFlaggedMessage;
use App\Actions\Admin\Chat\SetConversationFrozen;
use App\Actions\Admin\Members\PurgeDeletedMember;
use App\Actions\Chat\ReportChatMessages;
use App\Broadcasting\AdminQueuesChannel;
use App\Enums\ConversationStatus;
use App\Enums\FreezeReason;
use App\Enums\MessageFlagDecision;
use App\Enums\MessageFlagRule;
use App\Enums\MessageFlagStatus;
use App\Enums\PlanCode;
use App\Enums\ReportStatus;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Events\Admin\AdminQueueCountChanged;
use App\Events\Chat\ConversationFrozen;
use App\Events\Chat\MessageDeleted;
use App\Exceptions\Chat\ChatRefused;
use App\Exceptions\Chat\MessageFlagUnavailable;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageFlag;
use App\Models\Report;
use App\Models\User;
use App\Notifications\Chat\ChatWarning;
use App\Queries\Chat\ThreadWindow;
use App\Support\Facades\Settings;
use Database\Seeders\PlansSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/*
| P4.3a — A13 decisions on flagged chat messages: the permission matrix, the audited reveal of the
| flagged message with ±3 context ONLY, each decision's effect (dismiss, delete with the "removed by
| Oppam" tombstone, warn, freeze live, suspend through A03, escalate), report settlement, the
| admin unfreeze, the purge, and the admin.queues channel for chat moderators.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
    fakeMediaDisks();
});

/** @return array{0: User, 1: User, 2: Conversation, 3: Message, 4: MessageFlag} groom (sender), bride, thread, message, its PROFANITY flag */
function flaggedChat(): array
{
    [$groom, $bride, $thread] = chatConnected();
    $message = chatSend($groom, $bride, 'you are a bastard');

    return [$groom, $bride, $thread, $message, MessageFlag::query()->where('message_id', $message->id)->sole()];
}

function decideFlag(App\Models\AdminUser $admin, MessageFlag $flag, MessageFlagDecision $decision, ?string $note = null): void
{
    app(DecideMessageFlag::class)->handle($admin, $flag->refresh(), $decision, $note);
}

it('A13 acceptance: a reveal shows the flagged message with at most 3 messages either side of THIS thread — and writes an audit row', function (): void {
    [$groom, $bride, $thread] = chatConnected(PlanCode::Gold, PlanCode::Gold);
    $sent = [];
    foreach (range(1, 10) as $i) {
        $sent[$i] = chatSend($i % 2 ? $groom : $bride, $i % 2 ? $bride : $groom, $i === 6 ? 'you are a bastard' : "Ordinary message {$i}");
    }
    [$other, $third] = chatPairOf(groom('+919800000401'), bride('+919800000402'));
    chatSend($other, $third, 'An unrelated conversation');
    $flag = MessageFlag::query()->where('message_id', $sent[6]->id)->sole();
    $moderator = adminWithRole('moderator');

    $context = app(RevealFlaggedMessage::class)->handle($moderator, $flag);

    $shown = collect([...$context->before, $context->flagged, ...$context->after]);
    expect($shown->pluck('id')->all())->toBe(collect(range(3, 9))->map(fn (int $i): string => $sent[$i]->id)->all())
        ->and($context->flagged->isFlagged)->toBeTrue()
        ->and($context->flagged->senderLabel)->toBe($bride->profile->code)
        ->and($shown->pluck('text')->implode(' '))->not->toContain('Ordinary message 1 ')->not->toContain('Ordinary message 10')->not->toContain('unrelated')
        ->and($context->excerpt)->toBe('bastard');

    $audit = AuditLog::query()->where('action', 'chat.flag.revealed')->sole();
    expect($audit->actor_id)->toBe($moderator->id)->and($audit->subject_id)->toBe($flag->id)
        ->and($audit->after)->toMatchArray(['message_id' => $sent[6]->id, 'context_messages' => 6])
        ->and($audit->reason)->toBe(MessageFlagRule::Profanity->label());
});

it('permissions: reveal and every decision need chat.view_flagged plus their own key — finance, support and read-only are refused', function (string $role, MessageFlagDecision $decision): void {
    [, , , , $flag] = flaggedChat();

    expect(fn () => decideFlag(adminWithRole($role), $flag, $decision, 'reason enough'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RevealFlaggedMessage::class)->handle(adminWithRole($role), $flag))->toThrow(AuthorizationException::class)
        ->and($flag->refresh()->status)->toBe(MessageFlagStatus::Pending)
        ->and(AuditLog::query()->where('action', 'like', 'chat.%')->count())->toBe(0);
})->with(['finance', 'support', 'read_only'])->with([MessageFlagDecision::Dismiss, MessageFlagDecision::FreezeConversation]);

it('dismiss: the flag closes with who and when, audited; the badge goes down; deciding it again is refused', function (): void {
    [, , , $message, $flag] = flaggedChat();
    $moderator = adminWithRole('moderator');
    Event::fake([AdminQueueCountChanged::class]);

    decideFlag($moderator, $flag, MessageFlagDecision::Dismiss, 'Banter between them');

    $flag->refresh();
    expect($flag->status)->toBe(MessageFlagStatus::Dismissed)->and($flag->decision)->toBe(MessageFlagDecision::Dismiss)
        ->and($flag->decided_by_admin_id)->toBe($moderator->id)->and($flag->decided_at)->not->toBeNull()
        ->and($flag->decision_note)->toBe('Banter between them')
        ->and($message->refresh()->body)->toBe('you are a bastard');
    expect(AuditLog::query()->where('action', 'chat.flag.decided')->sole()->after)->toMatchArray(['decision' => 'DISMISS', 'status' => 'DISMISSED']);
    Event::assertDispatched(AdminQueueCountChanged::class, fn (AdminQueueCountChanged $e): bool => $e->queueKey === MessageFlag::QUEUE_KEY && $e->count === 0);

    expect(fn () => decideFlag($moderator, $flag, MessageFlagDecision::WarnSender))->toThrow(MessageFlagUnavailable::class);
});

it('delete message: text gone for both members ("removed by Oppam"), live; every open flag on it closes; the report is settled', function (): void {
    [$groom, $bride, $thread, $message, $flag] = flaggedChat();
    app(ReportChatMessages::class)->handle($bride, $groom->profile, 'ABUSIVE_MESSAGES', null, [$message->id]);
    Event::fake([MessageDeleted::class]);

    decideFlag(adminWithRole('moderator'), $flag, MessageFlagDecision::RemoveMessage);

    $message->refresh();
    expect($message->body)->toBeNull()->and($message->removed_by_admin_at)->not->toBeNull()
        ->and(MessageFlag::query()->where('message_id', $message->id)->open()->count())->toBe(0)
        ->and(MessageFlag::query()->where('message_id', $message->id)->pluck('decision')->unique()->all())->toBe([MessageFlagDecision::RemoveMessage])
        ->and(Report::query()->sole()->status)->toBe(ReportStatus::Actioned);
    Event::assertDispatched(MessageDeleted::class, fn (MessageDeleted $e): bool => $e->messageId === $message->id);

    $page = app(ThreadWindow::class)->window($thread, $bride->profile, false, null);
    $row = collect($page['messages'])->firstWhere('id', $message->id);
    expect($row->text)->toBe('This message was removed by Oppam')->and($row->deleted)->toBeTrue();
});

it('warn sender: a fixed-wording ChatWarning — never the message text — to the sender only', function (): void {
    [$groom, $bride, , , $flag] = flaggedChat();

    decideFlag(adminWithRole('moderator'), $flag, MessageFlagDecision::WarnSender);

    Notification::assertSentTo($groom, ChatWarning::class, function (ChatWarning $n) use ($groom): bool {
        $content = $n->content($groom);

        return ! str_contains($content->title.$content->body, 'bastard');
    });
    Notification::assertNotSentTo($bride, ChatWarning::class);
    expect($flag->refresh()->status)->toBe(MessageFlagStatus::Actioned);
});

it('R-M07-5 freeze: the thread turns read-only for both members, live; a moderator can open it again with a typed reason', function (): void {
    [$groom, $bride, $thread, , $flag] = flaggedChat();
    $moderator = adminWithRole('moderator');
    Event::fake([ConversationFrozen::class]);

    decideFlag($moderator, $flag, MessageFlagDecision::FreezeConversation);

    expect($thread->refresh()->status)->toBe(ConversationStatus::Frozen)->and($thread->frozen_reason)->toBe(FreezeReason::Moderation);
    Event::assertDispatched(ConversationFrozen::class, fn (ConversationFrozen $e): bool => $e->conversationId === $thread->id && $e->frozen
        && $e->broadcastOn()[0]->name === 'private-chat.'.$thread->id && $e->broadcastWith() === ['frozen' => true]);
    $refused = null;
    try {
        chatSend($groom, $bride, 'Can I still write?');
    } catch (ChatRefused $e) {
        $refused = $e;
    }
    expect($refused?->getStatusCode())->toBe(409);

    expect(fn () => app(SetConversationFrozen::class)->handle($moderator, $thread, false, 'no'))->toThrow(ValidationException::class);
    expect(fn () => app(SetConversationFrozen::class)->handle(adminWithRole('support'), $thread, false, 'Reviewed, it was fine'))->toThrow(AuthorizationException::class);

    expect(app(SetConversationFrozen::class)->handle($moderator, $thread, false, 'Reviewed, it was fine'))->toBeTrue();
    expect($thread->refresh()->status)->toBe(ConversationStatus::Open)->and($thread->frozen_reason)->toBeNull();
    Event::assertDispatched(ConversationFrozen::class, fn (ConversationFrozen $e): bool => ! $e->frozen);
    expect(AuditLog::query()->where('action', 'chat.unfrozen')->sole()->reason)->toBe('Reviewed, it was fine');
});

it('suspend sender: only with members.suspend (not a moderator), with a typed reason; then the A03 suspension runs', function (): void {
    [$groom, , , , $flag] = flaggedChat();

    expect(fn () => decideFlag(adminWithRole('moderator'), $flag, MessageFlagDecision::SuspendSender, 'Abusive to several members'))->toThrow(AuthorizationException::class);
    expect(fn () => decideFlag(adminWithRole('super_admin'), $flag, MessageFlagDecision::SuspendSender))->toThrow(ValidationException::class);

    decideFlag(adminWithRole('super_admin'), $flag, MessageFlagDecision::SuspendSender, 'Abusive to several members');

    expect($groom->refresh()->status)->toBe(UserStatus::Suspended)
        ->and($flag->refresh()->status)->toBe(MessageFlagStatus::Actioned)
        ->and(AuditLog::query()->where('action', 'members.suspended')->sole()->reason)->toBe('Abusive to several members');
});

it('escalate: the flag still waits, for a super admin only', function (): void {
    [, , , , $flag] = flaggedChat();
    $moderator = adminWithRole('moderator');

    decideFlag($moderator, $flag, MessageFlagDecision::Escalate, 'Not sure, threats?');
    expect($flag->refresh()->status)->toBe(MessageFlagStatus::Escalated)->and(MessageFlag::pendingCount())->toBe(1);

    expect(fn () => decideFlag($moderator, $flag, MessageFlagDecision::Dismiss))->toThrow(MessageFlagUnavailable::class);
    expect(fn () => decideFlag(adminWithRole('super_admin'), $flag, MessageFlagDecision::Escalate))->toThrow(MessageFlagUnavailable::class);

    decideFlag(adminWithRole('super_admin'), $flag, MessageFlagDecision::WarnSender);
    expect($flag->refresh()->status)->toBe(MessageFlagStatus::Actioned);
});

it('a report whose flags are all dismissed is DISMISSED (it no longer counts towards the auto-freeze)', function (): void {
    [$groom, $bride] = chatConnected();
    $one = chatSend($groom, $bride, 'Hello there');
    $two = chatSend($groom, $bride, 'Are you there');
    app(ReportChatMessages::class)->handle($bride, $groom->profile, 'SPAM', null, [$one->id, $two->id]);
    $moderator = adminWithRole('moderator');

    foreach (MessageFlag::query()->get() as $i => $flag) {
        decideFlag($moderator, $flag, MessageFlagDecision::Dismiss);
        expect(Report::query()->sole()->status)->toBe($i === 0 ? ReportStatus::Open : ReportStatus::Dismissed);
    }
});

it('purge (P1.7a rule): reports ABOUT a purged member go and their excerpts are wiped', function (): void {
    [$groom, $bride, , $message] = flaggedChat();
    chatSend($groom, $bride, 'call 98470 12345');
    app(ReportChatMessages::class)->handle($bride, $groom->profile, 'SPAM', null, [$message->id]);
    expect(MessageFlag::query()->whereNotNull('excerpt')->count())->toBe(2);

    $groom->delete();
    $this->travel(Settings::int(SettingKey::MembersPurgeAfterDays) + 1)->days();
    expect(app(PurgeDeletedMember::class)->handle(User::withTrashed()->findOrFail($groom->id)))->toBeTrue();

    expect(Report::query()->count())->toBe(0)
        ->and(MessageFlag::query()->whereNotNull('report_id')->count())->toBe(0)
        ->and(MessageFlag::query()->whereNotNull('excerpt')->count())->toBe(0);
});

it('purge: a report a purged member FILED stays as evidence on the other member (only its typed text goes)', function (): void {
    [$groom, $bride, , $message] = flaggedChat();
    app(ReportChatMessages::class)->handle($bride, $groom->profile, 'ABUSIVE_MESSAGES', 'He threatened me', [$message->id]);

    $bride->delete();
    $this->travel(Settings::int(SettingKey::MembersPurgeAfterDays) + 1)->days();
    expect(app(PurgeDeletedMember::class)->handle(User::withTrashed()->findOrFail($bride->id)))->toBeTrue();

    $report = Report::query()->sole();
    expect($report->details)->toBeNull()->and($report->reported_profile_id)->toBe($groom->profile->id)
        ->and(MessageFlag::query()->where('report_id', $report->id)->open()->count())->toBe(1)
        ->and(MessageFlag::query()->where('message_id', $message->id)->count())->toBe(2);   // the auto flag and the report flag
});

/** @return array{0: User, 1: list<User>, 2: list<Conversation>} the sender, 3 reporters, the sender's 4 threads (the last with a member who never reported) */
function reportFrozenSender(): array
{
    $sender = groom();
    $members = [];
    $threads = [];
    foreach (['+919800000501', '+919800000502', '+919800000503', '+919800000504'] as $i => $phone) {
        [, $member, $thread] = chatPairOf($sender->refresh(), bride($phone), $i === 0 ? PlanCode::Gold : null);
        chatSend($sender->refresh(), $member, 'Message number '.$i);
        $members[] = $member;
        $threads[] = $thread;
    }
    foreach (array_slice($members, 0, 3) as $reporter) {
        $thread = Conversation::query()->where('pair_key', Conversation::pairKey($reporter->profile->id, $sender->profile->id))->value('id');
        app(ReportChatMessages::class)->handle($reporter, $sender->profile, 'SPAM', null,
            Message::query()->where('conversation_id', $thread)->where('sender_profile_id', $sender->profile->id)->pluck('id')->all());
    }

    return [$sender->refresh(), array_slice($members, 0, 3), array_map(fn (Conversation $t): Conversation => $t->refresh(), $threads)];
}

it('M09 auto-freeze is undone as ONE unit: dismissing a report that takes the sender below the rule opens every report-frozen thread — a moderator\'s freeze stays', function (): void {
    [$sender, $reporters, $threads] = reportFrozenSender();
    expect(collect($threads)->every(fn (Conversation $t): bool => $t->status === ConversationStatus::Frozen))->toBeTrue();
    $moderator = adminWithRole('moderator');
    // A moderator also freezes the non-reporter's (already report-frozen) thread for a reason of their own:
    // it becomes the moderator's freeze — audited — and stays when the reports are dismissed.
    expect(app(SetConversationFrozen::class)->handle($moderator, $threads[3], true, 'Seen enough in this thread'))->toBeTrue()
        ->and($threads[3]->refresh()->frozen_reason)->toBe(FreezeReason::Moderation)
        ->and(AuditLog::query()->where('action', 'chat.frozen')->sole()->after)->toMatchArray(['status' => 'FROZEN', 'frozen_reason' => 'MODERATION']);
    Event::fake([ConversationFrozen::class]);

    $report = Report::query()->where('reporter_profile_id', $reporters[0]->profile->id)->sole();
    foreach (MessageFlag::query()->where('report_id', $report->id)->get() as $flag) {
        decideFlag($moderator, $flag, MessageFlagDecision::Dismiss);
    }

    foreach (array_slice($threads, 0, 3) as $thread) {
        expect($thread->refresh()->status)->toBe(ConversationStatus::Open)->and($thread->frozen_reason)->toBeNull();
    }
    expect($threads[3]->refresh()->status)->toBe(ConversationStatus::Frozen);
    Event::assertDispatchedTimes(ConversationFrozen::class, 3);
    expect(AuditLog::query()->where('action', 'chat.auto_unfrozen')->sole()->after)->toMatchArray(['threads_opened' => 3]);
});

it('decisions (d): "Freeze conversation" on a report-frozen thread makes it the moderator\'s freeze — dismissing the other reports leaves it frozen', function (): void {
    [$sender, $reporters, $threads] = reportFrozenSender();
    $moderator = adminWithRole('moderator');

    $second = Report::query()->where('reporter_profile_id', $reporters[1]->profile->id)->sole();
    decideFlag($moderator, MessageFlag::query()->where('report_id', $second->id)->firstOrFail(), MessageFlagDecision::FreezeConversation);
    expect($threads[1]->refresh()->frozen_reason)->toBe(FreezeReason::Moderation);

    // Reporters 1 and 3 dismissed: one counting reporter left, below the rule.
    foreach ([$reporters[0], $reporters[2]] as $reporter) {
        $report = Report::query()->where('reporter_profile_id', $reporter->profile->id)->sole();
        foreach (MessageFlag::query()->where('report_id', $report->id)->get() as $flag) {
            decideFlag($moderator, $flag, MessageFlagDecision::Dismiss);
        }
    }

    expect($threads[1]->refresh()->status)->toBe(ConversationStatus::Frozen)
        ->and($threads[1]->frozen_reason)->toBe(FreezeReason::Moderation)
        ->and($threads[0]->refresh()->status)->toBe(ConversationStatus::Open)
        ->and($threads[3]->refresh()->status)->toBe(ConversationStatus::Open);
});

it('M09: undoing one member\'s automatic freeze leaves a thread frozen while the OTHER member is still over the rule', function (): void {
    [$sender, $reporters, $threads] = reportFrozenSender();
    // The sender's 4th partner is reported by three members of their own (the shared thread with the sender is one of them).
    $partner = Conversation::query()->whereKey($threads[3]->id)->sole()->participants()->where('profile_id', '!=', $sender->profile->id)->value('profile_id');
    $partnerUser = User::query()->whereHas('profile', fn ($q) => $q->whereKey($partner))->sole();
    $sharedThread = $threads[3]->id;
    foreach (['+919800000601', '+919800000602', '+919800000603'] as $i => $phone) {
        [$partnerUser, $reporter] = chatPairOf($partnerUser->refresh(), groom($phone), $i === 0 ? PlanCode::Gold : null);
        chatSend($partnerUser, $reporter, 'Hello from the partner');
        app(ReportChatMessages::class)->handle($reporter, $partnerUser->profile, 'SPAM', null,
            Message::query()->where('sender_profile_id', $partnerUser->profile->id)
                ->where('conversation_id', Conversation::query()->where('pair_key', Conversation::pairKey($reporter->profile->id, $partnerUser->profile->id))->value('id'))
                ->pluck('id')->all());
    }

    expect(app(App\Actions\Admin\Chat\ReopenReportFrozenThreads::class)->handle(adminWithRole('moderator'), $sender->profile, 'Reviewed: false reports'))->toBe(3)
        ->and(Conversation::query()->find($sharedThread)?->status)->toBe(ConversationStatus::Frozen)
        ->and($threads[0]->refresh()->status)->toBe(ConversationStatus::Open);
});

it('a moderator can lift the automatic freeze for a member at once — every report-frozen thread, with a typed reason, audited', function (): void {
    [$sender, , $threads] = reportFrozenSender();
    $reopen = app(App\Actions\Admin\Chat\ReopenReportFrozenThreads::class);

    expect(fn () => $reopen->handle(adminWithRole('support'), $sender->profile, 'Reviewed: false reports'))->toThrow(AuthorizationException::class)
        ->and(fn () => $reopen->handle(adminWithRole('moderator'), $sender->profile, 'ok'))->toThrow(ValidationException::class);

    expect($reopen->handle(adminWithRole('moderator'), $sender->profile, 'Reviewed: false reports'))->toBe(4);
    expect(collect($threads)->every(fn (Conversation $t): bool => $t->refresh()->status === ConversationStatus::Open))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'chat.report_freeze_lifted')->sole()->reason)->toBe('Reviewed: false reports');
});

it('§9.3 admin.queues: an admin holding only chat.view_flagged may join; one without any queue permission may not', function (): void {
    Role::create(['name' => 'chat_only', 'guard_name' => 'admin'])->givePermissionTo('chat.view_flagged');
    $channel = new AdminQueuesChannel;

    expect($channel->join(adminWithRole('chat_only')))->toBeTrue()
        ->and($channel->join(adminWithRole('finance')))->toBeFalse();
});
