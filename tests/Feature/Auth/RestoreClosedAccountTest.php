<?php

declare(strict_types=1);

use App\Actions\Admin\Members\DeleteMember;
use App\Actions\Auth\LoginWithOtp;
use App\Actions\Auth\LoginWithPassword;
use App\Actions\Auth\RequestMemberOtp;
use App\Actions\Profile\CloseAccount;
use App\Enums\ClosureReason;
use App\Enums\OtpPurpose;
use App\Enums\PlanCode;
use App\Enums\ProfileStatus;
use App\Enums\UserStatus;
use App\Exceptions\Auth\AccountClosed;
use App\Exceptions\Auth\LoginFailed;
use App\Exceptions\Auth\OtpInvalid;
use App\Livewire\Member\Auth\Login;
use App\Livewire\Member\Auth\RestoreAccount;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\ProfileClosure;
use App\Models\Subscription;
use App\Models\User;
use App\ValueObjects\PhoneNumber;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
| P8.2c — restore a closed account by signing in (M14, owner 2026-11-08; rule 5 — owner review).
| Only after the right password or login code does a member learn their account is closed and can
| come back (R-M01-4: wrong / unknown answers stay identical). Restorable: closed by the member
| themselves, not anonymised, within `members.purge_after_days`. An A03 admin delete never is.
*/

const RC_PHONE = '+919811300001';

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    $this->seed(PlansSeeder::class);
    Notification::fake();
    $this->sms = fakeSms();
});

/** A member with a Gold plan who closed their own account (password = TEST_MEMBER_PASSWORD). */
function rcClosedMember(string $phone = RC_PHONE): User
{
    $member = memberWithPhone($phone);
    Subscription::factory()->create([
        'profile_id' => $member->profile->id,
        'plan_id' => Plan::query()->where('code', PlanCode::Gold->value)->value('id'),
        'starts_at' => now()->subDays(5), 'ends_at' => now()->addDays(25),
    ]);
    app(CloseAccount::class)->handle($member->refresh(), ClosureReason::NotInterested, null, TEST_MEMBER_PASSWORD);

    return User::withTrashed()->findOrFail($member->id);
}

function rcPasswordLogin(string $id, string $password = TEST_MEMBER_PASSWORD, string $ip = '10.2.0.1'): User
{
    return app(LoginWithPassword::class)->handle($id, $password, false, requestWithSession($ip));
}

function rcFailure(Closure $attempt): ?string
{
    return rescue($attempt, fn (Throwable $e) => $e::class.': '.$e->getMessage(), false);
}

it('R-M01-4: the right password for a closed account offers the restore — by mobile, email or profile code — and signs nobody in', function (string $kind): void {
    $member = rcClosedMember();
    $member->forceFill(['email' => 'closed@example.com'])->save();
    $code = Profile::withTrashed()->where('user_id', $member->id)->value('code');

    $id = match ($kind) {
        'mobile' => '98113 00001',
        'email' => 'Closed@Example.com',
        default => strtolower((string) $code),
    };

    expect(fn () => rcPasswordLogin($id))->toThrow(AccountClosed::class);
    expect(auth('web')->check())->toBeFalse();
})->with(['mobile', 'email', 'profile code']);

it('R-M01-4: a wrong password for a closed account gets exactly the answer an unknown number gets', function (): void {
    rcClosedMember();
    $expected = LoginFailed::class.': '.LoginFailed::invalidCredentials()->getMessage();

    expect(rcFailure(fn () => rcPasswordLogin('9811300001', 'wrong-pass1')))->toBe($expected)
        ->and(rcFailure(fn () => rcPasswordLogin('9811399999', 'wrong-pass1', '10.2.0.2')))->toBe($expected);
});

it('login code: a closed account is sent a code, and the right code offers the restore; a wrong one is refused as usual', function (): void {
    rcClosedMember();
    $phone = PhoneNumber::fromE164(RC_PHONE);

    app(RequestMemberOtp::class)->handle($phone, OtpPurpose::Login, '10.2.0.3');
    $code = (string) $this->sms->lastCodeFor(RC_PHONE, OtpPurpose::Login);
    expect($code)->not->toBe('');

    expect(fn () => app(LoginWithOtp::class)->handle($phone, $code === '000000' ? '111111' : '000000', false, requestWithSession()))
        ->toThrow(OtpInvalid::class);
    expect(fn () => app(LoginWithOtp::class)->handle($phone, $code, false, requestWithSession()))
        ->toThrow(AccountClosed::class);
});

it('not restorable — after the window, once anonymised, or deleted by an admin: the generic "incorrect" answer', function (string $case): void {
    if ($case === 'admin delete') {
        $member = memberWithPhone(RC_PHONE);
        app(DeleteMember::class)->handle(adminWithRole(), $member, $member->profile->code, 'Asked by email to delete.');
    } else {
        $member = rcClosedMember();
        if ($case === 'window passed') {
            $this->travel(31)->days();
        } else {
            $member->forceFill(['anonymised_at' => now()])->save();
        }
    }

    expect(rcFailure(fn () => rcPasswordLogin('9811300001')))
        ->toBe(LoginFailed::class.': '.LoginFailed::invalidCredentials()->getMessage());
})->with(['window passed', 'anonymised', 'admin delete']);

it('R-M14 restore: Login → /account/restore → "Restore my account" brings everything back and signs the member in', function (): void {
    $member = rcClosedMember();

    Livewire::test(Login::class)
        ->set('loginId', '9811300001')->set('password', TEST_MEMBER_PASSWORD)
        ->call('loginWithPassword')
        ->assertRedirect(route('member.account.restore'));
    expect(auth('web')->check())->toBeFalse();

    Livewire::test(RestoreAccount::class)
        ->assertSee('Your account is closed')
        ->call('restore')
        ->assertRedirect();

    $member = User::query()->findOrFail($member->id);
    $profile = Profile::query()->where('user_id', $member->id)->firstOrFail();
    expect(auth('web')->id())->toBe($member->id)
        ->and($member->status)->toBe(UserStatus::Active)
        ->and($profile->status)->toBe(ProfileStatus::Active)
        ->and(Subscription::query()->where('profile_id', $profile->id)->sole()->paused_at)->toBeNull()
        ->and(ProfileClosure::query()->where('user_id', $member->id)->sole()->restored_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'account.restored_by_member')->count())->toBe(1);
});

it('"Not now" leaves the account closed; a restored closure can\'t be used again', function (): void {
    $member = rcClosedMember();

    Livewire::test(Login::class)->set('loginId', '9811300001')->set('password', TEST_MEMBER_PASSWORD)->call('loginWithPassword');
    Livewire::test(RestoreAccount::class)->call('notNow')->assertRedirect(route('home'));

    expect(User::withTrashed()->findOrFail($member->id)->trashed())->toBeTrue();
    // The pending step is gone: the page sends the visitor back to sign in.
    Livewire::test(RestoreAccount::class)->assertRedirect(route('login'));
});

it('the restore page can\'t be opened without the sign-in step, or after its 10 minutes', function (): void {
    rcClosedMember();
    $this->get(memberUrl('/account/restore'))->assertRedirect(route('login'));

    Livewire::test(Login::class)->set('loginId', '9811300001')->set('password', TEST_MEMBER_PASSWORD)->call('loginWithPassword');
    $this->travel(11)->minutes();
    Livewire::test(RestoreAccount::class)->assertRedirect(route('login'));
});

it('the window closing between the sign-in and the click: refused with the generic answer, nothing restored', function (): void {
    $member = rcClosedMember();
    $this->travel(29)->days();
    Livewire::test(Login::class)->set('loginId', '9811300001')->set('password', TEST_MEMBER_PASSWORD)->call('loginWithPassword')
        ->assertRedirect(route('member.account.restore'));
    $page = Livewire::test(RestoreAccount::class);

    $this->travel(2)->days();
    $page->call('restore')->assertRedirect(route('login'));

    expect(User::withTrashed()->findOrFail($member->id)->trashed())->toBeTrue()->and(auth('web')->check())->toBeFalse();
});

// ---- Review round 1 (P8.2c): session fixation and the remaining auth cases ------------------------

it('session fixation: the session id changes when a closed account proves itself, and again when it is restored', function (): void {
    rcClosedMember();
    $planted = session()->getId();

    Livewire::test(Login::class)->set('loginId', '9811300001')->set('password', TEST_MEMBER_PASSWORD)->call('loginWithPassword');
    $afterProof = session()->getId();
    expect($afterProof)->not->toBe($planted);

    Livewire::test(RestoreAccount::class)->call('restore');
    expect(session()->getId())->not->toBe($afterProof)->and(auth('web')->check())->toBeTrue();
});

it('R-M01-4: asking for a login code gives the same answer for a closed number and an unknown one', function (): void {
    rcClosedMember();
    $ask = fn (string $e164, string $ip): ?string => rcFailure(fn () => app(RequestMemberOtp::class)->handle(PhoneNumber::fromE164($e164), OtpPurpose::Login, $ip));

    expect($ask(RC_PHONE, '10.2.1.1'))->toBeNull()->and($ask('+919811399999', '10.2.1.2'))->toBeNull();
});

it('an admin-deleted account is never offered the restore through a login code either', function (): void {
    $member = memberWithPhone(RC_PHONE);
    app(DeleteMember::class)->handle(adminWithRole(), $member, $member->profile->code, 'Asked by email to delete.');
    $phone = PhoneNumber::fromE164(RC_PHONE);

    app(RequestMemberOtp::class)->handle($phone, OtpPurpose::Login, '10.2.1.3');
    expect($this->sms->lastCodeFor(RC_PHONE, OtpPurpose::Login))->toBeNull();   // no code to a number with no restorable account
    expect(fn () => app(LoginWithOtp::class)->handle($phone, '123456', false, requestWithSession()))->toThrow(OtpInvalid::class);
});

it('closed, then suspended by an admin before the click: restore refused with the generic answer', function (): void {
    $member = rcClosedMember();
    Livewire::test(Login::class)->set('loginId', '9811300001')->set('password', TEST_MEMBER_PASSWORD)->call('loginWithPassword');
    $page = Livewire::test(RestoreAccount::class);

    $member->forceFill(['suspended_at' => now()])->save();
    $page->call('restore')->assertRedirect(route('login'));

    expect(User::withTrashed()->findOrFail($member->id)->trashed())->toBeTrue()->and(auth('web')->check())->toBeFalse();
});

it('a support restore closes the member\'s old closure: a later admin delete can\'t be self-restored', function (): void {
    $member = rcClosedMember();
    app(App\Actions\Admin\Members\RestoreMember::class)->handle(adminWithRole(), $member, 'Member asked by phone.');
    $member = User::query()->findOrFail($member->id);
    app(DeleteMember::class)->handle(adminWithRole(), $member, $member->profile->code, 'Asked by email to delete.');

    expect(rcFailure(fn () => rcPasswordLogin('9811300001')))
        ->toBe(LoginFailed::class.': '.LoginFailed::invalidCredentials()->getMessage());
});
