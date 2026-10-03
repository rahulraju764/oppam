<?php

declare(strict_types=1);

use App\Actions\Admin\Members\BulkMemberAction;
use App\Actions\Admin\Members\DeleteMember;
use App\Actions\Admin\Members\PurgeDeletedMember;
use App\Actions\Admin\Members\ReactivateMember;
use App\Actions\Admin\Members\RestoreMember;
use App\Actions\Admin\Members\SetMemberProfileHidden;
use App\Actions\Admin\Members\SuspendMember;
use App\Actions\Auth\SignInMember;
use App\Actions\Profile\SubmitProfile;
use App\Enums\MemberBulkAction;
use App\Enums\ModerationStatus;
use App\Enums\PlanCode;
use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\Admin\MemberNoteIsImmutable;
use App\Exceptions\Admin\MemberStateConflict;
use App\Jobs\Members\PurgeDeletedMembers;
use App\Models\AuditLog;
use App\Models\ContactDetail;
use App\Models\LoginEvent;
use App\Models\Media;
use App\Models\MemberNote;
use App\Models\OtpChallenge;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\Account\AccountReactivated;
use App\Notifications\Account\AccountSuspended;
use App\Services\Entitlements\EntitlementService;
use Database\Seeders\PlansSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;

/*
| P1.7a — A03 member lifecycle: suspend / reactivate, hide / unhide, two-stage deletion
| (soft delete → restore window → anonymise), each with its permission, typed reason and audit row.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    $this->seed(PlansSeeder::class);
});

/** An active member with a verified email and a running Gold plan. */
function a03Member(string $phone = '+919811100001'): User
{
    $member = memberWithPhone($phone);
    $member->forceFill(['email' => 'member'.substr($phone, -4).'@example.com', 'email_verified_at' => now()])->save();
    Subscription::factory()->create([
        'profile_id' => $member->profile->id,
        'plan_id' => Plan::query()->where('code', PlanCode::Gold->value)->value('id'),
        'starts_at' => now()->subDays(5), 'ends_at' => now()->addDays(25),
    ]);

    return $member->refresh();
}

function a03Audits(string $action): int
{
    return AuditLog::query()->where('action', $action)->count();
}

function a03Plan(User $member): PlanCode
{
    $entitlements = app(EntitlementService::class);
    $profile = Profile::withTrashed()->where('user_id', $member->id)->firstOrFail();
    $entitlements->forget($profile);

    return $entitlements->plan($profile)->code;
}

// ---- Suspend / reactivate ------------------------------------------------------------------------

it('A03 suspend: signs out everywhere, hides the profile, pauses the plan, audits, emails a neutral notice', function (): void {
    Notification::fake();
    $member = a03Member();
    $epoch = $member->session_epoch;
    $remember = $member->remember_token;

    app(SuspendMember::class)->handle(adminWithRole(), $member, 'Repeated abusive messages reported by three members.');

    $member->refresh();
    $profile = $member->profile;
    expect($member->status)->toBe(UserStatus::Suspended)
        ->and($member->suspended_at)->not->toBeNull()
        ->and($member->session_epoch)->toBe($epoch + 1)
        ->and($member->remember_token)->not->toBe($remember)
        ->and($profile->status)->toBe(ProfileStatus::Suspended)
        ->and($profile->previous_status)->toBe(ProfileStatus::Active)
        ->and(Profile::query()->searchable()->whereKey($profile->id)->exists())->toBeFalse()
        ->and(Subscription::query()->where('profile_id', $profile->id)->sole()->paused_at)->not->toBeNull()
        ->and(a03Plan($member))->toBe(PlanCode::Free)
        ->and(a03Audits('members.suspended'))->toBe(1)
        ->and(AuditLog::query()->where('action', 'members.suspended')->sole()->reason)->toBe('Repeated abusive messages reported by three members.');

    Notification::assertSentTo($member, AccountSuspended::class, function (AccountSuspended $mail) use ($member): bool {
        $text = implode(' ', $mail->toMail($member)->introLines);

        return ! str_contains($text, 'abusive');   // the internal reason is never sent
    });
});

it('A03 suspend: a suspended member is signed out on their next request', function (): void {
    $member = a03Member();
    // Signed in through the session (not actingAs), so each request loads the member from the DB.
    $this->withSession([
        auth()->guard('web')->getName() => $member->id,
        SignInMember::EPOCH_SESSION_KEY => $member->session_epoch,
    ]);
    $this->get(memberUrl('/me'))->assertOk();

    app(SuspendMember::class)->handle(adminWithRole(), $member, 'Fake profile confirmed by support.');
    auth()->forgetGuards();   // a new request: the guard loads the member afresh

    $this->get(memberUrl('/me'))->assertRedirect(route('login'));
    $this->assertGuest('web');
});

it('A03 suspend: no email to an unverified address', function (): void {
    Notification::fake();
    $member = a03Member();
    $member->forceFill(['email_verified_at' => null])->save();

    app(SuspendMember::class)->handle(adminWithRole(), $member, 'Fake profile confirmed by support.');

    Notification::assertNothingSent();
});

it('A03 reactivate: the plan resumes with the paused time added back; the member is told', function (): void {
    Notification::fake();
    $member = a03Member();
    $endsBefore = Subscription::query()->where('profile_id', $member->profile->id)->sole()->ends_at;

    app(SuspendMember::class)->handle(adminWithRole(), $member, 'Investigating a payment dispute.');
    $this->travel(3)->days();
    app(ReactivateMember::class)->handle(adminWithRole(), $member, 'Dispute resolved in the member\'s favour.');

    $member->refresh();
    $subscription = Subscription::query()->where('profile_id', $member->profile->id)->sole();
    expect($member->status)->toBe(UserStatus::Active)
        ->and($member->suspended_at)->toBeNull()
        ->and($member->profile->status)->toBe(ProfileStatus::Active)
        ->and($member->profile->previous_status)->toBeNull()
        ->and($subscription->paused_at)->toBeNull()
        ->and((int) $endsBefore->diffInDays($subscription->ends_at, true))->toBe(3)
        ->and(a03Plan($member))->toBe(PlanCode::Gold)
        ->and(a03Audits('members.reactivated'))->toBe(1);
    Notification::assertSentTo($member, AccountReactivated::class);
});

it('A03 suspend takes waiting items out of the A04 queue; reactivate puts them back and the profile waits again (never ACTIVE)', function (): void {
    $member = memberThroughStep(6);
    $item = app(SubmitProfile::class)->handle($member, $member->profile()->firstOrFail());

    app(SuspendMember::class)->handle(adminWithRole(), $member, 'Suspected duplicate account.');
    expect($item->refresh()->status)->toBe(ModerationStatus::Rejected)
        ->and($item->reason_category)->toBe('MEMBER_SUSPENDED');

    app(ReactivateMember::class)->handle(adminWithRole(), $member, 'Not a duplicate after all.');
    expect($item->refresh()->status)->toBe(ModerationStatus::Open)
        ->and($item->reason_category)->toBeNull()
        ->and(statusOf($member))->toBe(ProfileStatus::PendingReview);
});

it('A03 suspend / reactivate need members.suspend (support has members.edit only)', function (string $action): void {
    $member = a03Member();
    if ($action === 'reactivate') {
        app(SuspendMember::class)->handle(adminWithRole(), $member, 'Setting up the test case.');
    }
    $status = $member->refresh()->status;

    expect(fn () => match ($action) {
        'suspend' => app(SuspendMember::class)->handle(adminWithRole('support'), $member, 'Trying without permission.'),
        default => app(ReactivateMember::class)->handle(adminWithRole('support'), $member, 'Trying without permission.'),
    })->toThrow(AuthorizationException::class);

    expect($member->refresh()->status)->toBe($status);
})->with(['suspend', 'reactivate']);

it('A03 every member action needs a typed reason of 5–500 characters', function (string $reason): void {
    $member = a03Member();

    expect(fn () => app(SuspendMember::class)->handle(adminWithRole(), $member, $reason))->toThrow(ValidationException::class);
    expect($member->refresh()->status)->toBe(UserStatus::Active)
        ->and(a03Audits('members.suspended'))->toBe(0);
})->with(['empty' => [''], 'too short' => ['  abc '], 'too long' => [str_repeat('x', 501)]]);

it('refuses a suspend of a suspended account and a reactivate of an active one', function (): void {
    $member = a03Member();
    expect(fn () => app(ReactivateMember::class)->handle(adminWithRole(), $member, 'Nothing to reactivate.'))->toThrow(MemberStateConflict::class);

    app(SuspendMember::class)->handle(adminWithRole(), $member, 'First suspension.');
    expect(fn () => app(SuspendMember::class)->handle(adminWithRole(), $member, 'Second suspension.'))->toThrow(MemberStateConflict::class);
});

// ---- Hide / unhide -------------------------------------------------------------------------------

it('A03 hide: a live profile leaves search; unhide brings it back; needs members.edit', function (): void {
    $member = a03Member();

    expect(fn () => app(SetMemberProfileHidden::class)->handle(adminWithRole('moderator'), $member, true, 'Moderator has no edit right.'))
        ->toThrow(AuthorizationException::class);

    app(SetMemberProfileHidden::class)->handle(adminWithRole('support'), $member, true, 'Member asked support to hide it.');
    expect(statusOf($member))->toBe(ProfileStatus::Hidden)
        ->and($member->refresh()->status)->toBe(UserStatus::Active);   // can still sign in

    app(SetMemberProfileHidden::class)->handle(adminWithRole('support'), $member, false, 'Member asked to be visible again.');
    expect(statusOf($member))->toBe(ProfileStatus::Active)
        ->and(a03Audits('members.profile_hidden'))->toBe(1)
        ->and(a03Audits('members.profile_unhidden'))->toBe(1);
});

it('only a live profile can be hidden', function (): void {
    $member = memberWithPhone('+919811100002', ProfileStatus::PendingReview);

    expect(fn () => app(SetMemberProfileHidden::class)->handle(adminWithRole(), $member, true, 'Hide a waiting profile.'))
        ->toThrow(MemberStateConflict::class);
});

// ---- Two-stage deletion --------------------------------------------------------------------------

it('A03 delete needs the typed member code; a wrong code changes nothing', function (): void {
    $member = a03Member();

    expect(fn () => app(DeleteMember::class)->handle(adminWithRole(), $member, 'OPM00000', 'Member asked us to delete it.'))
        ->toThrow(ValidationException::class);

    expect($member->refresh()->trashed())->toBeFalse()
        ->and(a03Audits('members.deleted'))->toBe(0);
});

it('A03 delete (stage one): account and profile soft-deleted, sessions ended, plan paused; needs members.delete', function (): void {
    $member = a03Member();
    $code = $member->profile->code;

    expect(fn () => app(DeleteMember::class)->handle(adminWithRole('support'), $member, $code, 'Support may not delete.'))
        ->toThrow(AuthorizationException::class);

    app(DeleteMember::class)->handle(adminWithRole(), $member, strtolower($code), 'Member asked us to delete it.');

    $member = User::withTrashed()->findOrFail($member->id);
    $profile = Profile::withTrashed()->where('user_id', $member->id)->firstOrFail();
    expect($member->trashed())->toBeTrue()
        ->and($member->status)->toBe(UserStatus::Deleted)
        ->and($profile->trashed())->toBeTrue()
        ->and($profile->status)->toBe(ProfileStatus::Deleted)
        ->and($profile->previous_status)->toBe(ProfileStatus::Active)
        ->and(Subscription::query()->where('profile_id', $profile->id)->sole()->paused_at)->not->toBeNull()
        ->and(a03Audits('members.deleted'))->toBe(1);
});

it('A03 restore within the window brings the member back as they were', function (): void {
    $member = a03Member();
    app(DeleteMember::class)->handle(adminWithRole(), $member, $member->profile->code, 'Deleted by mistake.');
    $this->travel(29)->days();

    app(RestoreMember::class)->handle(adminWithRole(), $member, 'Deleted by mistake — restoring.');

    $member = User::query()->findOrFail($member->id);
    expect($member->status)->toBe(UserStatus::Active)
        ->and($member->profile->status)->toBe(ProfileStatus::Active)
        ->and(a03Plan($member))->toBe(PlanCode::Gold)
        ->and(a03Audits('members.restored'))->toBe(1);
});

it('A03 restore is refused once the window has passed', function (): void {
    $member = a03Member();
    app(DeleteMember::class)->handle(adminWithRole(), $member, $member->profile->code, 'Member asked us to delete it.');
    $this->travel(31)->days();

    expect(fn () => app(RestoreMember::class)->handle(adminWithRole(), $member, 'Too late to restore this one.'))
        ->toThrow(MemberStateConflict::class);
});

it('a member deleted while suspended comes back suspended, and reactivation then restores the earlier status', function (): void {
    $member = a03Member();
    app(SuspendMember::class)->handle(adminWithRole(), $member, 'Abuse investigation.');
    app(DeleteMember::class)->handle(adminWithRole(), $member, $member->profile->code, 'Closing the account.');

    app(RestoreMember::class)->handle(adminWithRole(), $member, 'Closed by mistake.');
    $member = User::query()->findOrFail($member->id);
    expect($member->status)->toBe(UserStatus::Suspended)
        ->and($member->profile->status)->toBe(ProfileStatus::Suspended)
        ->and(a03Plan($member))->toBe(PlanCode::Free);

    app(ReactivateMember::class)->handle(adminWithRole(), $member, 'Investigation closed.');
    expect(statusOf($member))->toBe(ProfileStatus::Active)
        ->and(a03Plan($member))->toBe(PlanCode::Gold);
});

it('A03 purge (stage two): after the window everything identifying is wiped; the tombstone, plans and audit rows stay', function (): void {
    fakeMediaDisks();
    Bus::fake([PerformConversionsJob::class]);
    $member = memberThroughStep(6);
    $member->forceFill(['email' => 'gone@example.com', 'email_verified_at' => now()])->save();
    $phone = $member->phone;
    $profile = $member->profile()->firstOrFail();
    (new LoginEvent)->forceFill(['user_id' => $member->id, 'method' => 'PASSWORD', 'device_hash' => str_repeat('a', 64), 'ip_address' => '10.0.0.1'])->save();
    Subscription::factory()->create(['profile_id' => $profile->id, 'plan_id' => Plan::query()->where('code', PlanCode::Silver->value)->value('id')]);
    app(DeleteMember::class)->handle(adminWithRole(), $member, $profile->code, 'Member asked us to delete it.');

    $this->travel(29)->days();
    (new PurgeDeletedMembers)->handle(app(PurgeDeletedMember::class));
    expect(User::withTrashed()->findOrFail($member->id)->anonymised_at)->toBeNull();   // not yet due

    $this->travel(2)->days();
    (new PurgeDeletedMembers)->handle(app(PurgeDeletedMember::class));

    $member = User::withTrashed()->findOrFail($member->id);
    $profile = Profile::withTrashed()->findOrFail($profile->id);
    expect($member->anonymised_at)->not->toBeNull()
        ->and($member->phone)->not->toBe($phone)->toStartWith('X')
        ->and($member->email)->toBeNull()
        ->and($profile->first_name)->toBe('Deleted')
        ->and($profile->last_name)->toBe('Member')
        ->and($profile->dob?->format('m-d'))->toBe('01-01')
        ->and($profile->about)->toBeNull()
        ->and($profile->code)->not->toBe('')
        ->and(ContactDetail::query()->where('profile_id', $profile->id)->exists())->toBeFalse()
        ->and(LoginEvent::query()->where('user_id', $member->id)->exists())->toBeFalse()
        ->and(Media::query()->where('model_id', $profile->id)->exists())->toBeFalse()
        ->and(Subscription::query()->where('profile_id', $profile->id)->exists())->toBeTrue()
        ->and(a03Audits('members.deleted'))->toBe(1)
        ->and(AuditLog::query()->where('action', 'members.purged')->sole()->actor_type->value)->toBe('SYSTEM')
        ->and(User::query()->where('phone', $phone)->exists())->toBeFalse();   // the number is free again

    // Idempotent: a second run changes nothing and writes no second audit row.
    (new PurgeDeletedMembers)->handle(app(PurgeDeletedMember::class));
    expect(a03Audits('members.purged'))->toBe(1);

    expect(fn () => app(RestoreMember::class)->handle(adminWithRole(), $member, 'Too late for this one.'))
        ->toThrow(MemberStateConflict::class);
});

it('A03 notes are append-only', function (): void {
    $note = MemberNote::factory()->create();

    expect(fn () => $note->forceFill(['body' => 'changed'])->save())->toThrow(MemberNoteIsImmutable::class)
        ->and(fn () => $note->delete())->toThrow(MemberNoteIsImmutable::class);
});

// ---- P1.7a review round 1 --------------------------------------------------------------------------

it('P1.7a review Major: A03 actions never touch a broker (or any non-member) account — 404', function (): void {
    $broker = User::factory()->create(['role' => UserRole::Broker, 'phone' => '+919811100090']);

    expect(fn () => app(SuspendMember::class)->handle(adminWithRole(), $broker, 'A broker slipped into the list.'))
        ->toThrow(ModelNotFoundException::class);

    $result = app(BulkMemberAction::class)->handle(adminWithRole(), MemberBulkAction::Suspend,
        [$broker->id], 'A broker id tampered into the selection.');

    expect($result->done)->toBe(0)->and($result->skipped)->toBe(1)
        ->and($broker->refresh()->status)->toBe(UserStatus::Active);
});

it('P1.7a review Major: the purge also removes OTP challenges (real phone + IP) and sessions', function (): void {
    $member = a03Member('+919811100091');
    (new OtpChallenge)->forceFill(['user_id' => $member->id, 'phone' => $member->phone, 'purpose' => 'LOGIN',
        'code_hash' => 'x', 'expires_at' => now(), 'ip_address' => '10.9.9.9'])->save();
    DB::table('sessions')->insert(['id' => 'sess-a03', 'user_id' => $member->id, 'ip_address' => '10.9.9.9',
        'user_agent' => 'x', 'payload' => '', 'last_activity' => now()->getTimestamp()]);
    $phone = $member->phone;
    app(DeleteMember::class)->handle(adminWithRole(), $member, $member->profile->code, 'Member asked us to delete it.');
    $this->travel(31)->days();

    app(PurgeDeletedMember::class)->handle(User::withTrashed()->findOrFail($member->id));

    expect(OtpChallenge::query()->where('phone', $phone)->orWhere('user_id', $member->id)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $member->id)->exists())->toBeFalse();
});

it('the purge job anonymises every due member in one run', function (): void {
    $members = [a03Member('+919811100092'), a03Member('+919811100093'), a03Member('+919811100094')];
    foreach ($members as $m) {
        app(DeleteMember::class)->handle(adminWithRole(), $m, $m->profile->code, 'Member asked us to delete it.');
    }
    $this->travel(31)->days();

    (new PurgeDeletedMembers)->handle(app(PurgeDeletedMember::class));

    expect(User::onlyTrashed()->whereNull('anonymised_at')->count())->toBe(0)
        ->and(a03Audits('members.purged'))->toBe(3);
});

it('the purge never anonymises a soft-deleted non-member account (brokers have their own A07 lifecycle)', function (): void {
    $broker = User::factory()->create(['role' => UserRole::Broker, 'phone' => '+919811100095']);
    $broker->delete();
    $this->travel(31)->days();

    (new PurgeDeletedMembers)->handle(app(PurgeDeletedMember::class));

    expect(app(PurgeDeletedMember::class)->handle(User::withTrashed()->findOrFail($broker->id)))->toBeFalse()
        ->and(User::withTrashed()->findOrFail($broker->id)->phone)->toBe('+919811100095');
});

it('an escalated item stays escalated across a suspension and reactivation', function (): void {
    $member = memberThroughStep(6);
    $item = app(SubmitProfile::class)->handle($member, $member->profile()->firstOrFail());
    $item->forceFill(['status' => ModerationStatus::Escalated, 'reason_note' => 'Needs a super admin.'])->save();

    app(SuspendMember::class)->handle(adminWithRole(), $member, 'Abuse investigation.');
    expect($item->refresh()->status)->toBe(ModerationStatus::Rejected);

    app(ReactivateMember::class)->handle(adminWithRole(), $member, 'Investigation closed.');
    expect($item->refresh()->status)->toBe(ModerationStatus::Escalated);
});
