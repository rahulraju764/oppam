<?php

declare(strict_types=1);

use App\Actions\Admin\Verification\ApproveVerification;
use App\Actions\Admin\Verification\ClaimVerificationRequest;
use App\Actions\Admin\Verification\EscalateVerification;
use App\Actions\Admin\Verification\RejectVerification;
use App\Actions\Admin\Verification\RevokeVerification;
use App\Enums\VerificationRejectReason;
use App\Enums\VerificationStatus;
use App\Events\Admin\AdminQueueCountChanged;
use App\Events\Verification\VerificationChanged;
use App\Exceptions\Verification\VerificationRequestUnavailable;
use App\Models\AuditLog;
use App\Models\Profile;
use App\Models\VerificationRequest;
use App\Notifications\Verification\VerificationApprovedNotification;
use App\Notifications\Verification\VerificationRejectedNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
| P6.1 — A05 decisions: approve (Verified badge live + notification), reject (reason → guidance),
| escalate (super admin decides), claim lock, permissions, force re-verification (A03).
*/

beforeEach(function (): void {
    seedAdminRoles();
    fakeVerificationDisk();
    Notification::fake();
});

function waitingRequest(array $profile = []): VerificationRequest
{
    return submitId(verificationMember($profile));
}

it('A05 approve: Verified badge on, request APPROVED, audited, member told live and notified (F05)', function (): void {
    Event::fake([VerificationChanged::class, AdminQueueCountChanged::class]);
    $request = waitingRequest();
    $officer = adminWithRole('verification_officer');

    app(ApproveVerification::class)->handle($officer, $request);

    $profile = Profile::query()->findOrFail($request->profile_id);
    expect($profile->is_verified)->toBeTrue()
        ->and($request->refresh()->status)->toBe(VerificationStatus::Approved)
        ->and($request->decided_by_admin_id)->toBe($officer->id)
        ->and($request->claimed_by_admin_id)->toBeNull();

    $log = AuditLog::query()->where('action', 'verification.approved')->sole();
    expect($log->actor_id)->toBe($officer->id)->and($log->subject_label)->toBe($profile->code);

    Event::assertDispatched(VerificationChanged::class, function (VerificationChanged $e) use ($profile): bool {
        return $e->broadcastOn()[0]->name === 'private-App.Models.User.'.$profile->user_id
            && $e->broadcastAs() === 'verification.changed'
            && $e->broadcastWith() === ['status' => 'APPROVED', 'verified' => true];   // no ids, no document data
    });
    Event::assertDispatched(AdminQueueCountChanged::class, fn (AdminQueueCountChanged $e): bool => $e->queueKey === 'verification' && $e->count === 0);
    Notification::assertSentTo($profile->user, VerificationApprovedNotification::class);
});

it('A05 reject: needs a reason, "Other" needs a note; the member gets the reason’s guidance + note', function (): void {
    Event::fake([VerificationChanged::class]);
    $request = waitingRequest();
    $officer = adminWithRole('verification_officer');

    expect(fn () => app(RejectVerification::class)->handle($officer, $request, VerificationRejectReason::Other, ''))
        ->toThrow(ValidationException::class);

    app(RejectVerification::class)->handle($officer, $request, VerificationRejectReason::Unreadable, 'The photo is blurred near the date of birth.');

    expect($request->refresh()->status)->toBe(VerificationStatus::Rejected)
        ->and($request->reject_reason)->toBe(VerificationRejectReason::Unreadable)
        ->and($request->memberGuidance())->toContain('sharp, well-lit')->toContain('blurred near the date of birth')
        ->and(Profile::query()->findOrFail($request->profile_id)->is_verified)->toBeFalse();

    Notification::assertSentTo($request->profile->user, VerificationRejectedNotification::class,
        fn (VerificationRejectedNotification $n): bool => str_contains($n->guidance, 'blurred near the date of birth'));
    expect(AuditLog::query()->where('action', 'verification.rejected')->sole()->reason)->toBe('The photo is blurred near the date of birth.');
});

it('A05 escalate: internal note required; then only a super admin decides', function (): void {
    $request = waitingRequest();
    $officer = adminWithRole('verification_officer');

    expect(fn () => app(EscalateVerification::class)->handle($officer, $request, 'no'))->toThrow(ValidationException::class);

    app(EscalateVerification::class)->handle($officer, $request, 'Photo looks edited near the name.');
    expect($request->refresh()->status)->toBe(VerificationStatus::Escalated)
        ->and($request->claimed_by_admin_id)->toBeNull()
        ->and(AuditLog::query()->where('action', 'verification.escalated')->sole()->reason)->toBe('Photo looks edited near the name.');

    expect(fn () => app(ApproveVerification::class)->handle(adminWithRole('verification_officer'), $request->refresh()))
        ->toThrow(AuthorizationException::class);

    app(ApproveVerification::class)->handle(adminWithRole('super_admin'), $request->refresh());
    expect($request->refresh()->status)->toBe(VerificationStatus::Approved);
});

it('A05 claim lock: a request held by one officer can’t be decided by another until the claim lapses', function (): void {
    $request = waitingRequest();
    $first = adminWithRole('verification_officer');
    $second = adminWithRole('verification_officer');

    app(ClaimVerificationRequest::class)->handle($first, $request);
    expect($request->refresh()->claimed_by_admin_id)->toBe($first->id);

    expect(fn () => app(ApproveVerification::class)->handle($second, $request))->toThrow(VerificationRequestUnavailable::class)
        ->and(fn () => app(RejectVerification::class)->handle($second, $request, VerificationRejectReason::Expired))->toThrow(VerificationRequestUnavailable::class)
        ->and($request->refresh()->status)->toBe(VerificationStatus::Pending);

    $this->travel(16)->minutes();
    app(ApproveVerification::class)->handle($second, $request->refresh());
    expect($request->refresh()->decided_by_admin_id)->toBe($second->id);
});

it('a decided request is never decided again', function (): void {
    $request = waitingRequest();
    $officer = adminWithRole('verification_officer');
    app(ApproveVerification::class)->handle($officer, $request);

    expect(fn () => app(RejectVerification::class)->handle($officer, $request->refresh(), VerificationRejectReason::Expired))
        ->toThrow(VerificationRequestUnavailable::class);
});

it('decisions need verification.approve (403 for roles without it)', function (string $role): void {
    $request = waitingRequest();

    expect(fn () => app(ApproveVerification::class)->handle(adminWithRole($role), $request))->toThrow(AuthorizationException::class)
        ->and(fn () => app(EscalateVerification::class)->handle(adminWithRole($role), $request, 'Please check.'))->toThrow(AuthorizationException::class)
        ->and($request->refresh()->status)->toBe(VerificationStatus::Pending)
        ->and(AuditLog::query()->where('action', 'like', 'verification.%')->where('action', '!=', 'verification.submitted')->count())->toBe(0);
})->with(['moderator', 'support', 'finance', 'read_only']);

it('a deleted member’s request leaves the queue and can’t be approved', function (): void {
    $request = waitingRequest();
    Profile::query()->whereKey($request->profile_id)->delete();

    expect(VerificationRequest::waitingCount())->toBe(0)
        ->and(fn () => app(ApproveVerification::class)->handle(adminWithRole('verification_officer'), $request))->toThrow(VerificationRequestUnavailable::class)
        ->and(Profile::withTrashed()->findOrFail($request->profile_id)->is_verified)->toBeFalse();
});

it('A03 force re-verification: badge off, request REVOKED, reason audited, member asked again (and may resubmit)', function (): void {
    Event::fake([VerificationChanged::class]);
    $request = waitingRequest();
    app(ApproveVerification::class)->handle(adminWithRole('verification_officer'), $request);
    $profile = Profile::query()->findOrFail($request->profile_id);
    $admin = adminWithRole('verification_officer');

    expect(fn () => app(RevokeVerification::class)->handle($admin, $profile, 'no'))->toThrow(ValidationException::class)
        ->and(fn () => app(RevokeVerification::class)->handle(adminWithRole('support'), $profile, 'Name changed after marriage'))->toThrow(AuthorizationException::class);

    app(RevokeVerification::class)->handle($admin, $profile, 'Member reported the ID belongs to a sibling.');

    expect($profile->refresh()->is_verified)->toBeFalse()
        ->and($request->refresh()->status)->toBe(VerificationStatus::Revoked)
        ->and($request->revoked_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'verification.revoked')->sole()->reason)->toBe('Member reported the ID belongs to a sibling.');
    Event::assertDispatched(VerificationChanged::class, fn (VerificationChanged $e): bool => $e->broadcastWith() === ['status' => 'REVOKED', 'verified' => false]);
    Notification::assertSentTo($profile->user, VerificationRejectedNotification::class, fn (VerificationRejectedNotification $n): bool => $n->revoked);

    expect(fn () => app(RevokeVerification::class)->handle($admin, $profile, 'Second time around'))->toThrow(ValidationException::class)
        ->and(submitId($profile->user)->status)->toBe(VerificationStatus::Pending);
});
