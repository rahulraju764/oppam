<?php

declare(strict_types=1);

use App\Actions\Admin\Safety\DecideAbuseCase;
use App\Actions\Admin\Safety\ExportEvidencePack;
use App\Actions\Admin\Safety\RevealCaseEvidence;
use App\Actions\Admin\Safety\SetLegalHold;
use App\Actions\Admin\Safety\TriageAbuseCase;
use App\Actions\Auth\RegisterMember;
use App\Actions\Chat\ReportChatMessages;
use App\Domain\Safety\BanList;
use App\Enums\AbuseCaseStatus;
use App\Enums\BannedIdentifierType;
use App\Enums\CaseOutcome;
use App\Enums\CasePriority;
use App\Enums\ConversationStatus;
use App\Enums\FreezeReason;
use App\Enums\MessageFlagStatus;
use App\Enums\ProfileStatus;
use App\Enums\ReportStatus;
use App\Enums\UserStatus;
use App\Exceptions\Safety\CaseUnavailable;
use App\Jobs\Members\PurgeDeletedMembers;
use App\Jobs\Safety\PageOnCallAdmins;
use App\Models\AbuseCase;
use App\Models\AuditLog;
use App\Models\BannedIdentifier;
use App\Models\MessageFlag;
use App\Models\Report;
use App\Models\SafetyAction;
use App\Models\User;
use App\Notifications\Safety\ReportReviewed;
use App\Notifications\Safety\SafetyWarning;
use App\ValueObjects\PhoneNumber;
use Database\Seeders\PlansSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
| P6.2 — A10 case review: outcomes (no action, warning, content removal, hide, suspend, ban
| blocklist), audited evidence reveal, triage, legal hold + evidence pack; F07 end to end.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    seedAdminRoles();
    Cache::flush();
    Notification::fake();
    Bus::fake([PageOnCallAdmins::class]);
});

/** @return array{0: User, 1: User, 2: AbuseCase, 3: string} groom (reported), bride (reporter), case, reported message id */
function chatCase(): array
{
    [$groom, $bride, $thread] = chatConnected();
    chatSend($groom, $bride, 'Hi, nice to meet you');
    chatSend($bride, $groom, 'Hello');   // her one free reply
    $bad = chatSend($groom, $bride, 'You are stupid');
    chatSend($groom, $bride, 'Reply now');
    app(ReportChatMessages::class)->handle($bride, $groom->profile, 'ABUSIVE_MESSAGES', null, [$bad->id]);

    return [$groom->refresh(), $bride->refresh(), openCaseOf($groom), $bad->id];
}

it('F07 warning: the case closes, reports and their A13 flags are actioned, a safety action + audit row are written, the member gets safety_warning, the reporter only “reviewed and closed”', function (): void {
    [$groom, $bride, $case] = chatCase();
    $admin = adminWithRole('moderator');

    app(DecideAbuseCase::class)->handle($admin, $case, CaseOutcome::Warning, 'First warning');

    $case->refresh();
    expect($case->status)->toBe(AbuseCaseStatus::Closed)->and($case->open_key)->toBeNull()->and($case->outcome)->toBe(CaseOutcome::Warning)
        ->and(Report::query()->sole()->status)->toBe(ReportStatus::Actioned)
        ->and(MessageFlag::query()->where('source', 'REPORT')->sole()->status)->toBe(MessageFlagStatus::Actioned)
        ->and(SafetyAction::query()->sole()->outcome)->toBe(CaseOutcome::Warning)
        ->and(AuditLog::query()->where('action', 'safety.case.decided')->where('subject_id', $case->id)->exists())->toBeTrue();
    Notification::assertSentTo($groom, SafetyWarning::class);
    Notification::assertSentTo($bride, ReportReviewed::class);
    Notification::assertNotSentTo($bride, SafetyWarning::class);
});

it('no action: reports dismissed — and a thread frozen automatically after reports opens again once below the rule', function (): void {
    [$groom, $bride, $case] = chatCase();
    $thread = App\Models\Conversation::query()->firstOrFail();
    $thread->forceFill(['status' => ConversationStatus::Frozen, 'frozen_reason' => FreezeReason::Reports, 'frozen_at' => now()])->save();

    app(DecideAbuseCase::class)->handle(adminWithRole('moderator'), $case, CaseOutcome::NoAction, null);

    expect(Report::query()->sole()->status)->toBe(ReportStatus::Dismissed)
        ->and($thread->refresh()->status)->toBe(ConversationStatus::Open);
    Notification::assertNotSentTo($groom, SafetyWarning::class);
    Notification::assertSentTo($bride, ReportReviewed::class);
});

it('content removal: the reported messages are removed for both, chosen photos of THIS member only are rejected', function (): void {
    fakeMediaDisks();
    [$groom, , $case, $badId] = chatCase();
    $photo = addTestPhoto($groom);
    $othersPhoto = addTestPhoto(bride('+919800000450'));

    app(DecideAbuseCase::class)->handle(adminWithRole('moderator'), $case, CaseOutcome::ContentRemoval, null, [$photo->uuid, $othersPhoto->uuid]);

    expect(App\Models\Message::query()->find($badId)->removed_by_admin_at)->not->toBeNull()
        ->and($photo->refresh()->moderation_status->value)->toBe('REJECTED')
        ->and($othersPhoto->refresh()->moderation_status->value)->not->toBe('REJECTED')
        ->and(SafetyAction::query()->sole()->details)->toBe(['messages_removed' => 1, 'photos_rejected' => 1]);
});

it('A10 ban: account BANNED, sessions revoked, profile out of search, threads frozen, phone / email / photos on the blocklist; the mobile can’t register again', function (): void {
    fakeMediaDisks();
    [$groom, , $case] = chatCase();
    $groom->forceFill(['email' => 'groom@example.com'])->save();
    addTestPhoto($groom);
    $epoch = $groom->session_epoch;

    app(DecideAbuseCase::class)->handle(adminWithRole('super_admin'), $case, CaseOutcome::Ban, 'Abuse and money requests');

    $groom->refresh();
    $bans = app(BanList::class);
    expect($groom->status)->toBe(UserStatus::Banned)->and($groom->session_epoch)->toBe($epoch + 1)
        ->and($groom->profile->status)->toBe(ProfileStatus::Suspended)
        ->and(App\Models\Conversation::query()->sole()->status)->toBe(ConversationStatus::Frozen)
        ->and($bans->isBanned(BannedIdentifierType::Phone, $groom->phone))->toBeTrue()
        ->and($bans->isBanned(BannedIdentifierType::Email, 'GROOM@example.com'))->toBeTrue()
        ->and(BannedIdentifier::query()->where('type', 'PHASH')->count())->toBe(1)
        ->and(BannedIdentifier::query()->where('value', $groom->phone)->exists())->toBeFalse();   // stored hashed

    // The account is later anonymised (the purge frees the number) — a new registration with the banned
    // number still creates nothing.
    fakeSms();
    $phone = $groom->phone;
    $groom->forceFill(['phone' => '+910000000001'])->save();
    $result = app(RegisterMember::class)->handle(registrationData(['phone' => PhoneNumber::fromE164($phone)]), '10.9.9.9');
    expect($result->user)->toBeNull()->and(User::query()->where('phone', $phone)->exists())->toBeFalse();

    // A new mobile with the banned email: the account is created, the banned email is not attached.
    $fresh = app(RegisterMember::class)->handle(registrationData(['phone' => PhoneNumber::fromParts('91', '9811122233'), 'email' => 'Groom@Example.com']), '10.9.9.8');
    expect($fresh->user)->not->toBeNull()->and($fresh->user->email)->toBeNull();
});

it('permissions: no safety.reports.act → 403; a moderator can’t ban (safety.ban), suspend (members.suspend) or hide (members.edit); nothing changes', function (string $role, CaseOutcome $outcome): void {
    [, , $case] = chatCase();

    expect(fn () => app(DecideAbuseCase::class)->handle(adminWithRole($role), $case, $outcome, 'Reason given here'))->toThrow(AuthorizationException::class);
    expect($case->refresh()->status)->toBe(AbuseCaseStatus::Open)->and(SafetyAction::query()->count())->toBe(0);
})->with([
    ['support', CaseOutcome::Warning],
    ['read_only', CaseOutcome::NoAction],
    ['moderator', CaseOutcome::Ban],
    ['moderator', CaseOutcome::Suspend],
    ['moderator', CaseOutcome::Hide],
]);

it('validation and state: suspend / ban need a typed reason; a decided case can’t be decided again', function (): void {
    [, , $case] = chatCase();
    $admin = adminWithRole('super_admin');

    expect(fn () => app(DecideAbuseCase::class)->handle($admin, $case, CaseOutcome::Suspend, ''))->toThrow(ValidationException::class);
    app(DecideAbuseCase::class)->handle($admin, $case, CaseOutcome::NoAction, null);
    expect(fn () => app(DecideAbuseCase::class)->handle($admin, $case, CaseOutcome::Warning, null))->toThrow(CaseUnavailable::class);
    expect(SafetyAction::query()->count())->toBe(1);
});

it('a new report after a decision opens a NEW case', function (): void {
    [$groom, , $case] = chatCase();
    app(DecideAbuseCase::class)->handle(adminWithRole('moderator'), $case, CaseOutcome::NoAction, null);

    profileReport(reporterNo(1), $groom, 'SPAM');

    expect(AbuseCase::query()->count())->toBe(2)->and(openCaseOf($groom)->id)->not->toBe($case->id);
});

it('A10 reveal: only the reported message and N around it, audited every time; needs safety.reports.view', function (): void {
    [, , $case, $badId] = chatCase();
    $groom = User::query()->whereHas('profile', fn ($q) => $q->where('id', $case->reported_profile_id))->sole();
    $bride = User::query()->where('phone', '+919800000098')->sole();
    foreach (range(1, 6) as $n) {   // more history after the reported message
        chatSend($groom, $bride, 'Later message '.$n);
    }

    $evidence = app(RevealCaseEvidence::class)->handle(adminWithRole('support'), $case);

    $rows = $evidence[0]->messages;
    expect(count($rows))->toBeLessThanOrEqual(7)
        ->and(collect($rows)->where('isFlagged', true)->pluck('id')->all())->toBe([$badId])
        ->and(AuditLog::query()->where('action', 'safety.evidence.revealed')->count())->toBe(1);
    expect(fn () => app(RevealCaseEvidence::class)->handle(adminWithRole('finance'), $case))->toThrow(AuthorizationException::class);
});

it('triage: assign to me and a priority change (typed reason) are audited; read-only admins are refused', function (): void {
    [, , $case] = chatCase();
    $admin = adminWithRole('moderator');

    app(TriageAbuseCase::class)->assignToMe($admin, $case);
    app(TriageAbuseCase::class)->changePriority($admin, $case, CasePriority::P0, 'Threats in messages');

    expect($case->refresh()->assigned_admin_id)->toBe($admin->id)->and($case->priority)->toBe(CasePriority::P0)
        ->and(AuditLog::query()->whereIn('action', ['safety.case.assigned', 'safety.case.priority_changed'])->count())->toBe(2);
    expect(fn () => app(TriageAbuseCase::class)->assignToMe(adminWithRole('read_only'), $case))->toThrow(AuthorizationException::class);
});

it('legal hold: super admin only; the member purge skips a held member; the evidence pack needs the hold and is audited', function (): void {
    [$groom, , $case] = chatCase();
    expect(fn () => app(SetLegalHold::class)->handle(adminWithRole('moderator'), $case, true, 'Police request 42'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ExportEvidencePack::class)->handle(adminWithRole('super_admin'), $case))->toThrow(AuthorizationException::class);

    $super = adminWithRole('super_admin');
    app(SetLegalHold::class)->handle($super, $case, true, 'Police request 42');
    $groom->delete();
    $this->travel(400)->days();
    (new PurgeDeletedMembers)->handle(app(App\Actions\Admin\Members\PurgeDeletedMember::class));
    expect(User::withTrashed()->find($groom->id)->anonymised_at)->toBeNull();

    $path = app(ExportEvidencePack::class)->handle($super, $case);
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue()->and($zip->locateName('chat-evidence.json'))->not->toBeFalse();
    $zip->close();
    @unlink($path);
    expect(AuditLog::query()->whereIn('action', ['safety.legal_hold.placed', 'safety.evidence_pack.exported'])->count())->toBe(2);
});
