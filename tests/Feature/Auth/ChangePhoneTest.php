<?php

declare(strict_types=1);

use App\Actions\Auth\ChangePhone;
use App\Actions\Auth\LoginWithPassword;
use App\Actions\Auth\SignInMember;
use App\Domain\Safety\BanList;
use App\Enums\BannedIdentifierType;
use App\Enums\NotificationEvent;
use App\Enums\OtpPurpose;
use App\Enums\SettingKey;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Events\Account\ForceLogout;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Auth\AccountChangeRefused;
use App\Exceptions\Auth\LoginFailed;
use App\Exceptions\Auth\OtpInvalid;
use App\Exceptions\Auth\OtpThrottled;
use App\Models\AuditLog;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\Account\AccountSecurityChanged;
use App\Support\Facades\Settings;
use App\ValueObjects\PhoneNumber;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
| P8.2b — change mobile number (PRD M14 Account, R-M01-1 / R-M01-2 / R-M01-4; owner decisions
| 2026-11-08): a code to the current number, then to the new one; taken / managed / banned numbers
| are refused with one generic message (never which reason) and get no code; the swap signs every
| other device out (epoch, remember token, live ForceLogout), keeps this one, notifies and audits.
| Never in a support session.
*/

beforeEach(function (): void {
    $this->sms = fakeSms();
    Notification::fake();
    Event::fake([ForceLogout::class]);
});

const CP_OLD = '+919876511001';
const CP_NEW = '+919876511002';

function cpMember(): User
{
    return memberWithPhone(CP_OLD);
}

function cpThroughStepOne(object $test, User $member): void
{
    $request = requestWithSession();
    $request->session()->put(SignInMember::EPOCH_SESSION_KEY, $member->session_epoch);
    app(ChangePhone::class)->start($member, '10.1.1.1');
    app(ChangePhone::class)->confirmOld($member, (string) $test->sms->lastCodeFor(CP_OLD, OtpPurpose::ChangePhone));
}

it('R-M14 happy path: both codes → the number is swapped, other devices signed out, this one kept, notice + audit', function (): void {
    $member = cpMember();
    $epoch = $member->session_epoch;
    $token = $member->remember_token;
    cpThroughStepOne($this, $member);

    app(ChangePhone::class)->sendNew($member, PhoneNumber::fromE164(CP_NEW), '10.1.1.1');
    app(ChangePhone::class)->confirmNew($member, (string) $this->sms->lastCodeFor(CP_NEW, OtpPurpose::ChangePhone), request());

    $member->refresh();
    expect($member->phone)->toBe(CP_NEW)
        ->and($member->phone_verified_at)->not->toBeNull()
        ->and($member->session_epoch)->toBe($epoch + 1)
        ->and($member->remember_token)->not->toBe($token)
        ->and(session(SignInMember::EPOCH_SESSION_KEY))->toBe($epoch + 1)   // this session re-stamped: stays in
        ->and(session(ChangePhone::SESSION_KEY))->toBeNull();
    Event::assertDispatched(ForceLogout::class, fn (ForceLogout $e): bool => $e->userId === (string) $member->id && $e->scope === ForceLogout::ALL);
    Notification::assertSentTo($member, AccountSecurityChanged::class, fn (AccountSecurityChanged $n): bool => $n->event() === NotificationEvent::AccountSecurity);

    $audit = AuditLog::query()->where('action', 'account.phone_changed')->sole();
    expect(json_encode($audit->after))->not->toContain(CP_NEW);   // phone masked in the audit log
});

it('R-M01-1: afterwards the new number signs in and the old one no longer does', function (): void {
    $member = cpMember();
    cpThroughStepOne($this, $member);
    app(ChangePhone::class)->sendNew($member, PhoneNumber::fromE164(CP_NEW), '10.1.1.1');
    app(ChangePhone::class)->confirmNew($member, (string) $this->sms->lastCodeFor(CP_NEW, OtpPurpose::ChangePhone), request());
    auth('web')->logout();

    expect(app(LoginWithPassword::class)->handle(CP_NEW, TEST_MEMBER_PASSWORD, false, requestWithSession('10.2.2.2'))->id)->toBe($member->id);
    auth('web')->logout();
    expect(fn () => app(LoginWithPassword::class)->handle(CP_OLD, TEST_MEMBER_PASSWORD, false, requestWithSession('10.2.2.3')))
        ->toThrow(LoginFailed::class);
});

it('step 2 is refused until the current number is proven, and after the window passes', function (): void {
    $member = cpMember();
    requestWithSession();

    expect(fn () => app(ChangePhone::class)->sendNew($member, PhoneNumber::fromE164(CP_NEW), '10.1.1.1'))
        ->toThrow(AccountChangeRefused::class, 'current number first');

    cpThroughStepOne($this, $member);
    $this->travel((int) config('oppam.auth.change_phone_window_minutes') + 1)->minutes();

    expect(fn () => app(ChangePhone::class)->sendNew($member, PhoneNumber::fromE164(CP_NEW), '10.1.1.1'))
        ->toThrow(AccountChangeRefused::class);
    expect($member->refresh()->phone)->toBe(CP_OLD);
});

it('confirmNew without a new number sent is refused', function (): void {
    $member = cpMember();
    cpThroughStepOne($this, $member);

    expect(fn () => app(ChangePhone::class)->confirmNew($member, '123456', request()))->toThrow(AccountChangeRefused::class);
});

it('R-M01-2: a wrong code refuses, three wrong codes use the challenge up', function (): void {
    $member = cpMember();
    requestWithSession();
    app(ChangePhone::class)->start($member, '10.1.1.1');
    $right = (string) $this->sms->lastCodeFor(CP_OLD, OtpPurpose::ChangePhone);
    $wrong = $right === '000000' ? '111111' : '000000';

    expect(fn () => app(ChangePhone::class)->confirmOld($member, $wrong))->toThrow(OtpInvalid::class);
    expect(fn () => app(ChangePhone::class)->confirmOld($member, $wrong))->toThrow(OtpInvalid::class);
    expect(fn () => app(ChangePhone::class)->confirmOld($member, $wrong))->toThrow(OtpInvalid::class);
    expect(fn () => app(ChangePhone::class)->confirmOld($member, $right))->toThrow(OtpInvalid::class);
    expect(session(ChangePhone::SESSION_KEY))->toBeNull();
});

it('R-M01-2: an expired code is refused', function (): void {
    $member = cpMember();
    requestWithSession();
    app(ChangePhone::class)->start($member, '10.1.1.1');
    $code = (string) $this->sms->lastCodeFor(CP_OLD, OtpPurpose::ChangePhone);

    $this->travel(Settings::int(SettingKey::OtpTtlMinutes) + 1)->minutes();

    expect(fn () => app(ChangePhone::class)->confirmOld($member, $code))->toThrow(OtpInvalid::class);
    expect(session(ChangePhone::SESSION_KEY))->toBeNull();
});

it('a code from another member\'s challenge on the same new number is refused (challenge user checked)', function (): void {
    $member = cpMember();
    $other = memberWithPhone('+919876511008');
    cpThroughStepOne($this, $member);
    app(ChangePhone::class)->sendNew($member, PhoneNumber::fromE164(CP_NEW), '10.1.1.1');

    // The other member asks for the same new number later: theirs is now the live code for it.
    $this->travel(31)->seconds();
    session()->put(ChangePhone::SESSION_KEY, ['user' => (string) $other->id, 'old_ok_at' => now()->getTimestamp(), 'new' => null]);
    app(ChangePhone::class)->sendNew($other, PhoneNumber::fromE164(CP_NEW), '10.1.1.9');
    $othersCode = (string) $this->sms->lastCodeFor(CP_NEW, OtpPurpose::ChangePhone);
    session()->put(ChangePhone::SESSION_KEY, ['user' => (string) $member->id, 'old_ok_at' => now()->getTimestamp(), 'new' => CP_NEW]);

    expect(fn () => app(ChangePhone::class)->confirmNew($member, $othersCode, request()))->toThrow(OtpInvalid::class);
    expect($member->refresh()->phone)->toBe(CP_OLD)->and($other->refresh()->phone)->toBe('+919876511008');
});

it('the step-1 proof belongs to its member: another member in the same session can\'t use it', function (): void {
    $member = cpMember();
    $other = memberWithPhone('+919876511009');
    cpThroughStepOne($this, $member);

    expect(fn () => app(ChangePhone::class)->sendNew($other, PhoneNumber::fromE164(CP_NEW), '10.1.1.1'))
        ->toThrow(AccountChangeRefused::class);
});

it('R-M01-1 / R-M01-4: a taken, client-held or banned number is refused with one generic message and gets no code', function (string $case): void {
    $member = cpMember();
    match ($case) {
        'another account' => memberWithPhone(CP_NEW),
        'managed profile client' => Profile::factory()->active()->create(['client_phone' => CP_NEW]),
        'banned' => app(BanList::class)->add(BannedIdentifierType::Phone, CP_NEW, null, null, null),
    };
    cpThroughStepOne($this, $member);

    expect(fn () => app(ChangePhone::class)->sendNew($member, PhoneNumber::fromE164(CP_NEW), '10.1.1.1'))
        ->toThrow(AccountChangeRefused::class, AccountChangeRefused::numberUnavailable()->getMessage());   // same words for every reason

    expect($this->sms->lastCodeFor(CP_NEW, OtpPurpose::ChangePhone))->toBeNull()
        ->and(app(ChangePhone::class)->progress($member)->newNumberMasked)->toBeNull();
    expect(fn () => app(ChangePhone::class)->confirmNew($member, '123456', request()))->toThrow(AccountChangeRefused::class);
    expect($member->refresh()->phone)->toBe(CP_OLD);
})->with(['another account', 'managed profile client', 'banned']);

it('R-M01-2: a refused number still spends the send limits (probing costs the same as texting)', function (): void {
    $member = cpMember();
    memberWithPhone(CP_NEW);
    cpThroughStepOne($this, $member);

    expect(fn () => app(ChangePhone::class)->sendNew($member, PhoneNumber::fromE164(CP_NEW), '10.1.1.1'))->toThrow(AccountChangeRefused::class);
    expect(fn () => app(ChangePhone::class)->sendNew($member, PhoneNumber::fromE164(CP_NEW), '10.1.1.1'))->toThrow(OtpThrottled::class);   // 30 s gap
});

it('a number taken between the two steps is refused at the swap, and nothing changes', function (): void {
    $member = cpMember();
    cpThroughStepOne($this, $member);
    app(ChangePhone::class)->sendNew($member, PhoneNumber::fromE164(CP_NEW), '10.1.1.1');
    $code = (string) $this->sms->lastCodeFor(CP_NEW, OtpPurpose::ChangePhone);
    User::factory()->create(['phone' => CP_NEW]);

    expect(fn () => app(ChangePhone::class)->confirmNew($member, $code, request()))->toThrow(AccountChangeRefused::class);
    expect($member->refresh()->phone)->toBe(CP_OLD);
    Event::assertNotDispatched(ForceLogout::class);
});

it('the same number is refused', function (): void {
    $member = cpMember();
    cpThroughStepOne($this, $member);

    expect(fn () => app(ChangePhone::class)->sendNew($member, PhoneNumber::fromE164(CP_OLD), '10.1.1.1'))
        ->toThrow(AccountChangeRefused::class, 'already your mobile number');
});

it('R-M01-2 send limits apply (30 s between codes)', function (): void {
    $member = cpMember();
    requestWithSession();
    app(ChangePhone::class)->start($member, '10.1.1.1');

    expect(fn () => app(ChangePhone::class)->start($member, '10.1.1.1'))->toThrow(OtpThrottled::class);
});

it('refused in an admin support session', function (): void {
    $member = cpMember();
    $this->actingAs($member, 'web');
    chatImpersonate($member);

    expect(fn () => app(ChangePhone::class)->start($member, '10.1.1.1'))->toThrow(ImpersonationRestricted::class);
    expect($this->sms->lastCodeFor(CP_OLD, OtpPurpose::ChangePhone))->toBeNull();
});

it('a suspended account or a bureau login gets 404', function (string $case): void {
    $member = cpMember();
    $member->forceFill($case === 'suspended' ? ['status' => UserStatus::Suspended] : ['role' => UserRole::Broker])->save();
    requestWithSession();

    expect(fn () => app(ChangePhone::class)->start($member->refresh(), '10.1.1.1'))->toThrow(NotFoundHttpException::class);
})->with(['suspended', 'broker']);
