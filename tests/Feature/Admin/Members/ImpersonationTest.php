<?php

declare(strict_types=1);

use App\Actions\Admin\Impersonation\StartImpersonation;
use App\Actions\Admin\Members\ResendRegistrationOtp;
use App\Actions\Admin\Members\ResetMemberPassword;
use App\Actions\Admin\Members\SuspendMember;
use App\Actions\Auth\SignInMember;
use App\Enums\AdminStatus;
use App\Enums\ImpersonationEndReason;
use App\Enums\OtpPurpose;
use App\Enums\UserRole;
use App\Exceptions\Admin\MemberStateConflict;
use App\Livewire\Admin\Members\Show as MemberShow;
use App\Livewire\Member\Auth\LogoutButton;
use App\Livewire\Member\Profile\Show as ProfileShow;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\ContactView;
use App\Models\ImpersonationSession;
use App\Models\LoginEvent;
use App\Models\ProfileView;
use App\Models\User;
use App\Notifications\Account\ImpersonationStarted;
use App\Notifications\Account\PasswordResetByAdmin;
use App\Notifications\Auth\NewDeviceSignIn;
use App\Services\Admin\Impersonation;
use Database\Seeders\PlansSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| P1.7b — A01 / A03 time-boxed impersonation (30 min, reason, banner, blocked actions, start + end
| audit rows, every impersonated write audited, member emailed at the start), admin password
| reset and resend-OTP. Held for the owner's own review (CLAUDE.md rule 5).
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    $this->seed(PlansSeeder::class);
});

function impMember(string $phone = '+919844400001'): User
{
    $member = memberWithPhone($phone);
    $member->forceFill(['email' => 'imp'.substr($phone, -4).'@example.com', 'email_verified_at' => now(), 'remember_token' => 'member-own-remember-token'])->save();

    return $member->refresh();
}

/** A running impersonation of $member, and this test client signed in to it the way the handoff leaves it. */
function impRunning(User $member, ?AdminUser $admin = null): ImpersonationSession
{
    $admin ??= adminWithRole();
    $row = ImpersonationSession::factory()->create(['admin_user_id' => $admin->id, 'admin_label' => $admin->email, 'user_id' => $member->id]);

    test()->withSession([
        auth()->guard('web')->getName() => $member->id,
        SignInMember::EPOCH_SESSION_KEY => $member->session_epoch,
        Impersonation::SESSION_KEY => $row->id,
    ]);

    return $row;
}

function impAudits(string $action): int
{
    return AuditLog::query()->where('action', $action)->count();
}

// ---- Start + handoff -----------------------------------------------------------------------------

it('A01 impersonation start: needs members.impersonate and a reason; audited; the member is emailed at the start (no reason sent)', function (): void {
    Notification::fake();
    $member = impMember();

    expect(fn () => app(StartImpersonation::class)->handle(adminWithRole('support'), $member, 'Support lacks the permission.', '127.0.0.1'))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(StartImpersonation::class)->handle(adminWithRole(), $member, '', '127.0.0.1'))
        ->toThrow(ValidationException::class);

    $token = app(StartImpersonation::class)->handle(adminWithRole(), $member, 'Member cannot find the photo upload button.', '127.0.0.1');

    $row = ImpersonationSession::query()->sole();
    expect(strlen($token))->toBe(64)
        ->and($row->token_hash)->toBe(hash('sha256', $token))->not->toBe($token)
        ->and($row->started_at)->toBeNull()
        ->and(impAudits('impersonation.started'))->toBe(1);
    Notification::assertSentTo($member, ImpersonationStarted::class, function (ImpersonationStarted $mail) use ($member): bool {
        return ! str_contains(implode(' ', $mail->toMail($member)->introLines), 'photo upload');
    });
});

it('A01 handoff: the token signs this browser in as the member for 30 minutes — no login event, no new-device mail, no Referer', function (): void {
    Notification::fake();
    $member = impMember();
    $token = app(StartImpersonation::class)->handle(adminWithRole(), $member, 'Checking the wizard for the member.', '127.0.0.1');

    $this->get(memberUrl('/impersonate/'.$token))
        ->assertRedirect(route('member.profile.me'))
        ->assertHeader('Referrer-Policy', 'no-referrer');

    $this->assertAuthenticatedAs($member, 'web');
    $row = ImpersonationSession::query()->sole();
    expect($row->started_at)->not->toBeNull()
        ->and((int) round($row->started_at->diffInMinutes($row->expires_at, true)))->toBe(30)
        ->and($row->token_hash)->toBeNull()
        ->and(session(Impersonation::SESSION_KEY))->toBe($row->id)
        ->and(LoginEvent::query()->where('user_id', $member->id)->exists())->toBeFalse();
    Notification::assertNotSentTo($member, NewDeviceSignIn::class);
});

it('A01 handoff tokens are single-use, expire after 60 seconds and only work from the admin\'s IP', function (string $case): void {
    $member = impMember();
    $token = app(StartImpersonation::class)->handle(adminWithRole(), $member, 'Checking the wizard for the member.', '127.0.0.1');

    match ($case) {
        'reused' => $this->get(memberUrl('/impersonate/'.$token))->assertRedirect(),
        'expired' => $this->travel(61)->seconds(),
        default => null,
    };
    if ($case === 'reused') {
        // The copied link opened in another browser: no session of the first one.
        auth()->guard('web')->logoutCurrentDevice();
        $this->flushSession();
    }

    $request = $case === 'other ip' ? $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40']) : $this;
    $request->get(memberUrl('/impersonate/'.$token))->assertNotFound();
    $this->assertGuest('web');
})->with(['reused', 'expired', 'other ip']);

it('a made-up token is a 404', function (): void {
    $this->get(memberUrl('/impersonate/'.str_repeat('a', 64)))->assertNotFound();
});

it('A01 impersonation only of active, phone-verified members — never a broker', function (string $case): void {
    $member = impMember();
    match ($case) {
        'suspended' => app(SuspendMember::class)->handle(adminWithRole(), $member, 'Abuse investigation.'),
        'unverified' => $member->forceFill(['phone_verified_at' => null])->save(),
        default => $member->forceFill(['role' => UserRole::Broker])->save(),
    };

    expect(fn () => app(StartImpersonation::class)->handle(adminWithRole(), $member->refresh(), 'Trying anyway.', '127.0.0.1'))
        ->toThrow($case === 'broker' ? ModelNotFoundException::class : MemberStateConflict::class);
})->with(['suspended', 'unverified', 'broker']);

it('one live impersonation per member; the same admin starting again replaces their earlier one', function (): void {
    $member = impMember();
    $first = adminWithRole();
    app(StartImpersonation::class)->handle($first, $member, 'First look at the account.', '127.0.0.1');

    expect(fn () => app(StartImpersonation::class)->handle(adminWithRole(), $member, 'Second admin at the same time.', '127.0.0.1'))
        ->toThrow(MemberStateConflict::class);

    app(StartImpersonation::class)->handle($first, $member, 'Starting again after closing the tab.', '127.0.0.1');
    expect(ImpersonationSession::query()->whereNotNull('ended_at')->sole()->end_reason)->toBe(ImpersonationEndReason::NotUsed)
        ->and(ImpersonationSession::query()->whereNull('ended_at')->count())->toBe(1);
});

// ---- While impersonating -------------------------------------------------------------------------

it('A01 banner: every member page says it is a support view, until when, with an End button', function (): void {
    $member = impMember();
    impRunning($member);

    $this->get(memberUrl('/me'))->assertOk()
        ->assertSee('Support view')
        ->assertSee('End session');
});

it('A01 the session ends itself after 30 minutes: signed out, row EXPIRED, audited', function (): void {
    $member = impMember();
    $row = impRunning($member);
    $this->travel(31)->minutes();

    $this->get(memberUrl('/me'))->assertRedirect(route('login'));

    $this->assertGuest('web');
    expect($row->refresh()->end_reason)->toBe(ImpersonationEndReason::Expired)
        ->and(impAudits('impersonation.ended'))->toBe(1)
        ->and($member->refresh()->remember_token)->toBe('member-own-remember-token');   // the member's own devices stay signed in
});

it('A01 End session: a confirmation page linking back to the admin panel, row ENDED and audited; the member\'s own sign-ins are untouched', function (): void {
    $member = impMember();
    $row = impRunning($member);
    $epoch = $member->session_epoch;

    $this->post(memberUrl('/impersonation/end'))->assertRedirect(route('impersonation.ended'));
    $this->get(route('impersonation.ended'))->assertOk()->assertSee('Support session ended')
        ->assertSee(route('admin.members.show', ['profile' => $member->profile->code]), false);

    $this->assertGuest('web');
    expect($row->refresh()->end_reason)->toBe(ImpersonationEndReason::Ended)
        ->and(impAudits('impersonation.ended'))->toBe(1)
        ->and($member->refresh()->remember_token)->toBe('member-own-remember-token')
        ->and($member->session_epoch)->toBe($epoch);
});

it('the member\'s "Log out" during an impersonation ends it without signing the real member out elsewhere', function (): void {
    $member = impMember();
    $row = ImpersonationSession::factory()->create(['user_id' => $member->id]);
    $this->actingAs($member, 'web');
    session()->put(Impersonation::SESSION_KEY, $row->id);

    Livewire::test(LogoutButton::class)->call('logout');

    expect($row->refresh()->end_reason)->toBe(ImpersonationEndReason::SignedOut)
        ->and($member->refresh()->remember_token)->toBe('member-own-remember-token');
});

it('A01 blocked while impersonating: "log out other devices" (the member\'s sessions)', function (): void {
    $member = impMember();
    $row = ImpersonationSession::factory()->create(['user_id' => $member->id]);
    $this->actingAs($member, 'web');
    session()->put(Impersonation::SESSION_KEY, $row->id);
    $epoch = $member->session_epoch;

    Livewire::test(LogoutButton::class)->call('logoutOtherDevices')->assertSee('Not available while our team is viewing this account');

    expect($member->refresh()->session_epoch)->toBe($epoch);
});

it('owner decision: no contact reveal (spends quota) and no profile view recorded (reaches others) while impersonating', function (): void {
    $member = bride('+919844400002');
    $other = groom('+919844400003');
    $row = ImpersonationSession::factory()->create(['user_id' => $member->id]);
    $this->actingAs($member, 'web');
    session()->put(Impersonation::SESSION_KEY, $row->id);

    Livewire::test(ProfileShow::class, ['profile' => $other->profile->code])
        ->call('viewContact')
        ->assertSet('contactError', 'Not available while our team is viewing this account for support.');

    expect(ContactView::query()->count())->toBe(0)
        ->and(ProfileView::query()->count())->toBe(0);
});

// ---- Admin page ------------------------------------------------------------------------------------

it('"View as member" is offered only with members.impersonate and redirects to the handoff', function (): void {
    $member = impMember();

    $this->actingAs(adminWithRole('support'), 'admin');
    Livewire::test(MemberShow::class, ['profile' => $member->profile->code])->assertDontSee('View as member')
        ->set('reason', 'Support tries anyway.')->call('impersonate')->assertForbidden();

    $this->actingAs(adminWithRole(), 'admin');
    $redirect = Livewire::test(MemberShow::class, ['profile' => $member->profile->code])->assertSee('View as member')
        ->set('reason', 'Member asked for help with photos.')->call('impersonate')->effects['redirect'] ?? '';
    expect($redirect)->toContain('/impersonate/');
});

// ---- Admin password reset & resend OTP ---------------------------------------------------------------

it('A03 reset password: the old password stops working, every session ends, the member is emailed; needs members.edit', function (): void {
    Notification::fake();
    $member = impMember();
    $epoch = $member->session_epoch;

    expect(fn () => app(ResetMemberPassword::class)->handle(adminWithRole('moderator'), $member, 'Moderators cannot reset.'))
        ->toThrow(AuthorizationException::class);

    app(ResetMemberPassword::class)->handle(adminWithRole('support'), $member, 'Member locked out, verified by phone call.');

    $member->refresh();
    expect(Hash::check(TEST_MEMBER_PASSWORD, $member->password))->toBeFalse()
        ->and($member->session_epoch)->toBe($epoch + 1)
        ->and($member->remember_token)->not->toBe('member-own-remember-token')
        ->and(impAudits('members.password_reset'))->toBe(1);
    Notification::assertSentTo($member, PasswordResetByAdmin::class);
});

it('A03 resend OTP: a fresh registration code to an unverified member only, within the send limits; audited', function (): void {
    $sms = fakeSms();
    $member = impMember('+919844400004');

    expect(fn () => app(ResendRegistrationOtp::class)->handle(adminWithRole('support'), $member, 'Member says no code arrived.', '127.0.0.1'))
        ->toThrow(MemberStateConflict::class);   // already verified

    $member->forceFill(['phone_verified_at' => null])->save();
    app(ResendRegistrationOtp::class)->handle(adminWithRole('support'), $member, 'Member says no code arrived.', '127.0.0.1');

    expect($sms->lastCodeFor('+919844400004', OtpPurpose::Register))->not->toBeNull()
        ->and(impAudits('members.otp_resent'))->toBe(1);

    // The 30-second gap between two codes applies to admins too.
    expect(fn () => app(ResendRegistrationOtp::class)->handle(adminWithRole('support'), $member, 'Asking again at once.', '127.0.0.1'))
        ->toThrow(MemberStateConflict::class);
});

// ---- P1.7b review round 1 ----------------------------------------------------------------------------

it('P1.7b review Major: "log out other devices" from the member\'s phone ends the impersonation (REVOKED, audited) without undoing the member\'s re-issued cookie', function (): void {
    $member = impMember();
    $row = impRunning($member);
    // The member, on their own phone, logs out other devices: epoch bumped, a fresh remember token issued to that phone.
    $member->forceFill(['session_epoch' => $member->session_epoch + 1, 'remember_token' => 'reissued-to-members-phone'])->save();

    $this->get(memberUrl('/me'))->assertRedirect(route('login'));

    $this->assertGuest('web');
    expect($row->refresh()->end_reason)->toBe(ImpersonationEndReason::Revoked)
        ->and(impAudits('impersonation.ended'))->toBe(1)
        ->and($member->refresh()->remember_token)->toBe('reissued-to-members-phone');
});

it('P1.7b review Major: suspending the member mid-session ends the impersonation (REVOKED, audited)', function (): void {
    $member = impMember();
    $row = impRunning($member);

    app(SuspendMember::class)->handle(adminWithRole(), $member, 'Abuse found while helping.');
    $this->get(memberUrl('/me'))->assertRedirect(route('login'));

    expect($row->refresh()->end_reason)->toBe(ImpersonationEndReason::Revoked)
        ->and(impAudits('impersonation.ended'))->toBe(1);
});

it('M01: a stale session signed out after "log out other devices" no longer rotates the remember token', function (): void {
    $member = impMember();
    $this->withSession([auth()->guard('web')->getName() => $member->id, SignInMember::EPOCH_SESSION_KEY => $member->session_epoch]);
    $member->forceFill(['session_epoch' => $member->session_epoch + 1, 'remember_token' => 'reissued-to-this-device'])->save();

    $this->get(memberUrl('/me'))->assertRedirect(route('login'));

    expect($member->refresh()->remember_token)->toBe('reissued-to-this-device');
});

it('P1.7b review Major: starting again in the same browser hands over to the new session (the old one REPLACED)', function (): void {
    $admin = adminWithRole();
    $first = impMember('+919844400010');
    $second = impMember('+919844400011');
    $old = impRunning($first, $admin);

    $token = app(StartImpersonation::class)->handle($admin, $second, 'Now helping the second member.', '127.0.0.1');
    $this->get(memberUrl('/impersonate/'.$token))->assertRedirect(route('member.profile.me'));

    $this->assertAuthenticatedAs($second, 'web');
    expect($old->refresh()->end_reason)->toBe(ImpersonationEndReason::Replaced)
        ->and(session(Impersonation::SESSION_KEY))->not->toBe($old->id);
});

it('P1.7b review: an impersonation ends (REVOKED) as soon as its admin is suspended', function (): void {
    $admin = adminWithRole();
    $member = impMember();
    $row = impRunning($member, $admin);
    $admin->forceFill(['status' => AdminStatus::Suspended])->save();

    $this->get(memberUrl('/me'))->assertRedirect(route('login'));

    expect($row->refresh()->end_reason)->toBe(ImpersonationEndReason::Revoked);
});

it('P1.7b review Major (owner decision): every change made while impersonating is audited with the admin as actor — names only, never values', function (): void {
    Route::middleware('web')->domain((string) config('oppam.app_domain'))
        ->post('/_test/impersonated-write', fn () => 'saved')->name('test.impersonated-write');
    app('router')->getRoutes()->refreshNameLookups();
    $admin = adminWithRole();
    $member = impMember();
    impRunning($member, $admin);

    $this->get(memberUrl('/me'))->assertOk();   // reading is not recorded
    $this->post(memberUrl('/_test/impersonated-write'), ['about' => 'Secret new about text'])->assertOk();

    $row = AuditLog::query()->where('action', 'impersonation.action')->sole();
    expect($row->actor_id)->toBe($admin->id)
        ->and($row->subject_id)->toBe($member->id)
        ->and($row->after['route'] ?? null)->toBe('test.impersonated-write')
        ->and(json_encode($row->after))->not->toContain('Secret new about text');
});

it('the impersonation start is throttled (each start emails the member)', function (): void {
    $admin = adminWithRole();
    $member = impMember();
    foreach (range(1, 3) as $i) {
        app(StartImpersonation::class)->handle($admin, $member, 'Attempt number '.$i.' to help.', '127.0.0.1');
    }

    expect(fn () => app(StartImpersonation::class)->handle($admin, $member, 'One attempt too many.', '127.0.0.1'))
        ->toThrow(MemberStateConflict::class);
});

it('a guest can\'t end an impersonation', function (): void {
    $this->post(memberUrl('/impersonation/end'))->assertRedirect(route('login'));
});

it('P1.7b review Major: no admin password reset for a never-verified phone (it would lock the member out)', function (): void {
    $member = impMember();
    $member->forceFill(['phone_verified_at' => null])->save();

    expect(fn () => app(ResetMemberPassword::class)->handle(adminWithRole(), $member, 'Member cannot sign in.'))
        ->toThrow(MemberStateConflict::class);

    $this->actingAs(adminWithRole(), 'admin');
    Livewire::test(MemberShow::class, ['profile' => $member->profile->code])
        ->assertDontSeeHtml("name: 'member-reset-password' })");   // the button that opens the dialog
});

it('reset password and resend OTP re-check members.edit on the page; resend refuses a suspended member', function (): void {
    $member = impMember();

    $this->actingAs(adminWithRole('moderator'), 'admin');
    Livewire::test(MemberShow::class, ['profile' => $member->profile->code])
        ->set('reason', 'Moderator tries a reset.')->call('resetPassword')->assertForbidden();
    Livewire::test(MemberShow::class, ['profile' => $member->profile->code])
        ->set('reason', 'Moderator tries a resend.')->call('resendOtp')->assertForbidden();

    app(SuspendMember::class)->handle(adminWithRole(), $member, 'Abuse investigation.');
    $member->forceFill(['phone_verified_at' => null])->save();
    expect(fn () => app(ResendRegistrationOtp::class)->handle(adminWithRole(), $member->refresh(), 'Resend to a suspended member.', '127.0.0.1'))
        ->toThrow(MemberStateConflict::class);
});

// ---- P1.7b review rounds 2–3: the Livewire branch of the action audit ---------------------------------

/** POST a Livewire update payload as the impersonating browser (the snapshot need not be valid: the row is written first). */
function impLivewirePost(array $components): TestResponse
{
    return test()->postJson(route('default-livewire.update'), ['components' => $components]);
}

function impSnapshot(string $name = 'member.profile.my-profile'): string
{
    return (string) json_encode(['data' => [], 'memo' => ['id' => 'x', 'name' => $name], 'checksum' => 'forged']);
}

it('P1.7b review round 2: a Livewire action while impersonating is audited with component, method and field names — never values', function (): void {
    $admin = adminWithRole();
    impRunning(impMember(), $admin);

    impLivewirePost([[
        'snapshot' => impSnapshot(),
        'updates' => ['about' => 'Secret about text'],
        'calls' => [['path' => '', 'method' => 'save', 'params' => ['Secret param']], ['path' => '', 'method' => '$set', 'params' => ['gender', 'MALE-SECRET']]],
    ]]);

    $row = AuditLog::query()->where('action', 'impersonation.action')->sole();
    $livewire = $row->after['livewire'][0] ?? [];
    expect($row->actor_id)->toBe($admin->id)
        ->and($livewire['component'] ?? null)->toBe('member.profile.my-profile')
        ->and($livewire['calls'] ?? null)->toBe(['save', '$set:gender'])
        ->and($livewire['fields'] ?? null)->toBe(['about'])
        ->and(json_encode($row->after))->not->toContain('Secret')->not->toContain('MALE-SECRET');
});

it('P1.7b review round 2: a refresh-only Livewire request writes no audit row', function (): void {
    impRunning(impMember());

    impLivewirePost([['snapshot' => impSnapshot(), 'updates' => [], 'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]]]]);

    expect(impAudits('impersonation.action'))->toBe(0);
});

it('P1.7b review round 2: a malformed Livewire payload is still on record ("unparsed"), never skipped', function (array $components): void {
    impRunning(impMember());

    impLivewirePost($components);

    $row = AuditLog::query()->where('action', 'impersonation.action')->sole();
    expect(json_encode($row->after))->toContain('unparsed');
})->with([
    'call method is not a string' => [[['snapshot' => impSnapshot(), 'calls' => [['method' => 'save'], ['method' => []]]]]],
    'one good call and a bad params shape' => [[['snapshot' => impSnapshot(), 'calls' => [['method' => 'save', 'params' => 'x'], 7]]]],
]);

it('a Livewire payload without a readable component name is refused before anything runs (404), so there is nothing to audit', function (): void {
    impRunning(impMember());

    impLivewirePost([['snapshot' => ['memo' => ['name' => 'x']], 'calls' => [['method' => 'save']]]])->assertNotFound();

    expect(impAudits('impersonation.action'))->toBe(0);
});

it('P1.7b review round 3: an event dispatched to a component (runs #[On] listeners) is recorded by event name', function (): void {
    impRunning(impMember());

    impLivewirePost([['snapshot' => impSnapshot(), 'updates' => [], 'calls' => [['path' => '', 'method' => '__dispatch', 'params' => ['photos-changed', ['secret' => 'x']]]]]]);

    $row = AuditLog::query()->where('action', 'impersonation.action')->sole();
    expect($row->after['livewire'][0]['calls'] ?? null)->toBe(['__dispatch:photos-changed'])
        ->and(json_encode($row->after))->not->toContain('secret');
});

it('P1.7b review round 2: ending the session is not itself recorded as an impersonated action', function (): void {
    impRunning(impMember());

    $this->post(memberUrl('/impersonation/end'));

    expect(impAudits('impersonation.action'))->toBe(0)
        ->and(impAudits('impersonation.ended'))->toBe(1);
});

it('M01: the member\'s own "Log out" signs out this device only (other "stay logged in" devices keep working)', function (): void {
    $member = impMember();
    $this->actingAs($member, 'web');

    Livewire::test(LogoutButton::class)->call('logout');

    expect($member->refresh()->remember_token)->toBe('member-own-remember-token');
});
