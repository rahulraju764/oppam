<?php

declare(strict_types=1);

use App\Actions\Auth\CompleteRegistration;
use App\Actions\Auth\RegisterMember;
use App\Enums\CreatedFor;
use App\Enums\Gender;
use App\Enums\LoginMethod;
use App\Enums\OtpPurpose;
use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\Auth\OtpInvalid;
use App\Exceptions\Auth\OtpThrottled;
use App\Exceptions\Auth\RegistrationFailed;
use App\Models\LoginEvent;
use App\Models\OtpChallenge;
use App\Models\Profile;
use App\Models\User;
use App\ValueObjects\PhoneNumber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| P1.1 — M01 registration: account + DRAFT profile, REGISTER code, verification, sign-in.
| R-M01-1 (one account per mobile / email) WITHOUT ever saying a number is registered
| (owner decision 2026-09-29), unverified holders released, per-IP cap.
*/

beforeEach(function (): void {
    $this->sms = fakeSms();
});

function registerResult(array $overrides = [], string $ip = '10.0.0.1'): App\Data\Auth\RegistrationResult
{
    return app(RegisterMember::class)->handle(registrationData($overrides), $ip);
}

function register(array $overrides = [], string $ip = '10.0.0.1'): User
{
    $user = registerResult($overrides, $ip)->user;
    expect($user)->not->toBeNull();

    return $user;
}

function completeRegistration(User $user, string $code): User
{
    return app(CompleteRegistration::class)->handle(PhoneNumber::fromE164($user->phone), $user->id, $code, requestWithSession());
}

it('creates an unverified member with a DRAFT profile and texts the REGISTER code', function (): void {
    $user = register();

    $profile = $user->profile;

    expect($user->phone)->toBe('+919876543210')
        ->and($user->email)->toBe('anjali@example.com')
        ->and($user->role)->toBe(UserRole::Member)
        ->and($user->status)->toBe(UserStatus::Active)
        ->and($user->created_for)->toBe(CreatedFor::Self)
        ->and($user->hasVerifiedPhone())->toBeFalse()
        ->and($profile->status)->toBe(ProfileStatus::Draft)
        ->and($profile->code)->toMatch('/^OPM\d{5,}$/')
        ->and($profile->first_name)->toBe('Anjali')
        ->and($profile->last_name)->toBe('Thomas')
        ->and($profile->gender)->toBe(Gender::Female)
        ->and($profile->dob?->format('Y-m-d'))->toBe('1998-05-12')
        ->and($this->sms->lastCodeFor('+919876543210', OtpPurpose::Register))->toMatch('/^\d{6}$/')
        ->and(auth('web')->check())->toBeFalse();   // not signed in before the code
});

it('stores the password hashed', function (): void {
    $user = register();

    expect($user->getAttributes()['password'])->not->toBe(TEST_MEMBER_PASSWORD)
        ->and(password_verify(TEST_MEMBER_PASSWORD, $user->getAttributes()['password']))->toBeTrue();
});

it('allows a DRAFT profile without the step-1 basics (they arrive in the wizard)', function (): void {
    $user = register(['lastName' => null, 'dob' => null]);

    expect($user->profile->last_name)->toBeNull()
        ->and($user->profile->dob)->toBeNull()
        ->and($user->profile->age())->toBeNull();
});

it('refuses (in the database) a non-DRAFT profile without the step-1 basics', function (): void {
    $profile = register(['dob' => null])->profile;

    expect(fn () => DB::table('profiles')->where('id', $profile->id)->update(['status' => ProfileStatus::PendingReview->value]))
        ->toThrow(QueryException::class);
})->skip(fn (): bool => ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true), 'CHECK constraints need MySQL/MariaDB');

it('R-M01-1 + R-M01-4: a registered number creates nothing, raises nothing and texts the owner instead', function (): void {
    $owner = memberWithPhone('+919876543210');

    $result = registerResult(['email' => 'new@example.com']);

    expect($result->user)->toBeNull()                                   // nothing created…
        ->and($result->phone->e164())->toBe('+919876543210')            // …but the caller carries on as usual
        ->and($result->otpNotice)->toBeNull()
        ->and(User::query()->count())->toBe(1)
        ->and($this->sms->sent)->toBe([])                               // no code: nothing to verify
        ->and($this->sms->accountExistsNotices)->toBe(['+919876543210'])
        ->and($owner->refresh()->email)->not->toBe('new@example.com');
});

it('R-M01-4: a registered number spends the same send limits as a new one', function (): void {
    memberWithPhone('+919876543210');

    registerResult();

    expect(fn () => app(App\Actions\Auth\SendOtp::class)->handle(PhoneNumber::fromParts('91', '9876543210'), OtpPurpose::Register, '10.0.0.1', null))
        ->toThrow(OtpThrottled::class);   // the 30 s gap was spent
});

it('no code can complete a registration for a number that already had an account', function (): void {
    memberWithPhone('+919876543210');
    registerResult();

    expect(fn () => app(CompleteRegistration::class)->handle(PhoneNumber::fromParts('91', '9876543210'), null, '123456', requestWithSession()))
        ->toThrow(OtpInvalid::class, OtpInvalid::wrongOrExpired()->getMessage());
});

it('R-M01-1: silently leaves out an email that belongs to another account (case-insensitive)', function (): void {
    User::factory()->create(['email' => 'anjali@example.com']);

    $user = register(['email' => 'Anjali@Example.com']);

    expect($user->email)->toBeNull()
        ->and(User::query()->where('email', 'anjali@example.com')->count())->toBe(1);
});

it('R-M01-1: treats a number on a deleted account (30-day recovery window) as registered', function (): void {
    memberWithPhone('+919876543210')->delete();

    expect(registerResult()->user)->toBeNull()
        ->and($this->sms->accountExistsNotices)->toBe(['+919876543210']);
});

it('releases a number held by an account that never verified it (no squatting)', function (): void {
    $squatter = register(['firstName' => 'Squatter', 'email' => 'squat@example.com']);
    $this->travel(31)->seconds();

    $owner = register(['email' => 'owner@example.com']);

    expect(User::withTrashed()->find($squatter->id))->toBeNull()
        ->and(Profile::withTrashed()->where('user_id', $squatter->id)->exists())->toBeFalse()
        ->and($owner->phone)->toBe('+919876543210')
        ->and($owner->profile->first_name)->toBe('Anjali');
});

it('P1.7a: still releases a never-verified registration that an admin wrote a note on', function (): void {
    $squatter = register(['firstName' => 'Squatter', 'email' => 'squat@example.com']);
    App\Models\MemberNote::factory()->create(['user_id' => $squatter->id]);
    $this->travel(31)->seconds();

    $owner = register(['email' => 'owner@example.com']);

    expect(User::withTrashed()->find($squatter->id))->toBeNull()
        ->and(App\Models\MemberNote::query()->where('user_id', $squatter->id)->exists())->toBeFalse()
        ->and($owner->phone)->toBe('+919876543210');
});

it('frees an email held by an unverified account', function (): void {
    $stale = register(['phone' => PhoneNumber::fromParts('91', '9000000001')]);

    $user = register(['phone' => PhoneNumber::fromParts('91', '9000000002')]);

    expect($user->email)->toBe('anjali@example.com')
        ->and($stale->refresh()->email)->toBeNull();
});

it('never releases a suspended, banned or broker account that holds the number (no ban evasion)', function (Closure $holder): void {
    $existing = $holder();

    expect(registerResult()->user)->toBeNull();

    expect(User::query()->whereKey($existing->id)->exists())->toBeTrue()
        ->and($this->sms->accountExistsNotices)->toBe([]);   // they never proved the number: no SMS to it
})->with([
    'suspended, unverified' => fn (): User => User::factory()->unverified()->suspended()->create(['phone' => '+919876543210']),
    'banned, unverified' => fn (): User => User::factory()->unverified()->banned()->create(['phone' => '+919876543210']),
    'broker, unverified' => fn (): User => User::factory()->unverified()->broker()->create(['phone' => '+919876543210']),
]);

it('caps registrations per IP per hour', function (): void {
    config(['oppam.auth.registrations_per_ip_per_hour' => 2]);

    register(['phone' => PhoneNumber::fromParts('91', '9000000001'), 'email' => null]);
    register(['phone' => PhoneNumber::fromParts('91', '9000000002'), 'email' => null]);

    expect(fn () => register(['phone' => PhoneNumber::fromParts('91', '9000000003'), 'email' => null]))
        ->toThrow(RegistrationFailed::class, 'Too many registrations');
});

it('keeps the account and reports a notice when the send limit stops the code', function (): void {
    register();                                   // code #1 texted

    $result = registerResult();                   // re-register within 30 s

    expect($result->user?->exists)->toBeTrue()
        ->and($result->otpNotice)->toContain('seconds before asking for another code')
        ->and($this->sms->countFor('+919876543210'))->toBe(1);
});

it('verifies the mobile, signs the member in and records the login', function (): void {
    $user = register();

    completeRegistration($user, (string) $this->sms->lastCodeFor('+919876543210', OtpPurpose::Register));

    expect($user->refresh()->hasVerifiedPhone())->toBeTrue()
        ->and(auth('web')->id())->toBe($user->id)
        ->and(LoginEvent::query()->sole()->method)->toBe(LoginMethod::Registration);
});

it('does not verify or sign in with a wrong code', function (): void {
    $user = register();
    $code = $this->sms->lastCodeFor('+919876543210');

    expect(fn () => completeRegistration($user, $code === '000000' ? '111111' : '000000'))->toThrow(OtpInvalid::class);

    expect($user->refresh()->hasVerifiedPhone())->toBeFalse()
        ->and(auth('web')->check())->toBeFalse();
});

it('refuses a REGISTER code that was issued to another account', function (): void {
    $user = register();
    $code = (string) $this->sms->lastCodeFor('+919876543210');
    OtpChallenge::query()->update(['user_id' => User::factory()->create()->id]);

    expect(fn () => completeRegistration($user, $code))->toThrow(OtpInvalid::class);

    expect($user->refresh()->hasVerifiedPhone())->toBeFalse();
});

it('never takes role or status from the registration input', function (): void {
    // RegistrationData has no role/status fields at all; the Action sets them.
    expect(collect((new ReflectionClass(App\Data\Auth\RegistrationData::class))->getProperties())->map->getName()->all())
        ->not->toContain('role')
        ->not->toContain('status');
});

it('R-M01-4: hashes the password for a registered number too (no response-time difference)', function (): void {
    memberWithPhone('+919876543210');

    Illuminate\Support\Facades\Hash::shouldReceive('make')->once()->with(TEST_MEMBER_PASSWORD)->andReturn('$2y$04$unusedunusedunusedunuseuunusedunusedunusedunusedunused');

    expect(registerResult()->user)->toBeNull();
});
