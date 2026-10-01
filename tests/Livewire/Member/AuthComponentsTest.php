<?php

declare(strict_types=1);

use App\Enums\CreatedFor;
use App\Enums\Gender;
use App\Enums\OtpPurpose;
use App\Enums\ProfileStatus;
use App\Livewire\Member\Auth\ForgotPassword;
use App\Livewire\Member\Auth\Login;
use App\Livewire\Member\Auth\LogoutButton;
use App\Livewire\Member\Auth\Register;
use App\Livewire\Member\Auth\VerifyOtp;
use App\Livewire\Member\Onboarding\Wizard;
use App\Livewire\Public\QuickRegister;
use App\Models\User;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P1.1 — M01 Livewire screens: render, validation, actions, authorization, locked props,
| loading states. Business rules themselves are covered by tests/Feature/Auth.
*/

beforeEach(function (): void {
    $this->sms = fakeSms();
});

function fillHero(mixed $component, array $overrides = []): mixed
{
    $values = array_merge([
        'form.createdFor' => CreatedFor::Self->value,
        'form.name' => 'Anjali',
        'form.gender' => Gender::Female->value,
        'form.dobDay' => '12',
        'form.dobMonth' => '05',
        'form.dobYear' => '1998',
        'form.countryCode' => '91',
        'form.mobile' => '98765 43210',
        'form.email' => 'anjali@example.com',
        'form.password' => TEST_MEMBER_PASSWORD,
        'form.terms' => true,
    ], $overrides);

    foreach ($values as $key => $value) {
        $component->set($key, $value);
    }

    return $component;
}

// ---- Home hero: QuickRegister -------------------------------------------------------------

it('renders the hero form with "Profile for" and a loading state on submit', function (): void {
    Livewire::test(QuickRegister::class)
        ->assertOk()
        ->assertSeeHtml('id="reg-created-for"')
        ->assertSeeHtml('wire:loading.attr="disabled"')
        ->assertSee('Create an account for free');
});

it('registers from the hero and goes to the code screen', function (): void {
    fillHero(Livewire::test(QuickRegister::class))
        ->call('register')
        ->assertHasNoErrors()
        ->assertRedirect(route('register.verify'));

    $user = User::query()->sole();

    expect(session(VerifyOtp::PENDING_SESSION_KEY))->toBe(['phone' => '+919876543210', 'user_id' => $user->id])
        ->and($user->profile->dob?->format('Y-m-d'))->toBe('1998-05-12')
        ->and($this->sms->lastCodeFor('+919876543210', OtpPurpose::Register))->not->toBeNull();
});

it('validates every hero field and writes nothing', function (): void {
    Livewire::test(QuickRegister::class)
        ->call('register')
        ->assertHasErrors(['form.createdFor', 'form.name', 'form.gender', 'form.dob', 'form.mobile', 'form.password', 'form.terms']);

    expect(User::query()->count())->toBe(0)
        ->and($this->sms->sent)->toBe([]);
});

it('rejects invalid input', function (array $overrides, string $field): void {
    fillHero(Livewire::test(QuickRegister::class), $overrides)
        ->call('register')
        ->assertHasErrors([$field]);

    expect(User::query()->count())->toBe(0);
})->with([
    'bride under 18' => [['form.dobYear' => (string) (now()->year - 17)], 'form.dob'],
    'groom under 21' => [['form.gender' => 'MALE', 'form.dobYear' => (string) (now()->year - 20)], 'form.dob'],
    'impossible date' => [['form.dobDay' => '31', 'form.dobMonth' => '02'], 'form.dob'],
    'bad mobile' => [['form.mobile' => '12345'], 'form.mobile'],
    'unsupported country' => [['form.countryCode' => '92'], 'form.countryCode'],
    'bad email' => [['form.email' => 'not-an-email'], 'form.email'],
    'short password' => [['form.password' => 'abc12'], 'form.password'],
    'password without a number' => [['form.password' => 'onlyletters'], 'form.password'],
    'name with digits' => [['form.name' => 'R2D2'], 'form.name'],
    'terms not accepted' => [['form.terms' => false], 'form.terms'],
    'unknown profile-for' => [['form.createdFor' => 'BOSS'], 'form.createdFor'],
]);

it('takes the gender from "Profile for" whatever the browser sends', function (): void {
    fillHero(Livewire::test(QuickRegister::class), [
        'form.createdFor' => CreatedFor::Son->value,
        'form.gender' => Gender::Female->value,          // tampered after the select locked it
        'form.dobYear' => '1995',
    ])->call('register')->assertHasNoErrors();

    expect(User::query()->sole()->profile->gender)->toBe(Gender::Male);
});

it('R-M01-4: a registered number goes to the same code screen, with no message, and the owner is texted', function (): void {
    memberWithPhone('+919876543210');

    fillHero(Livewire::test(QuickRegister::class))
        ->call('register')
        ->assertHasNoErrors()
        ->assertDontSee('already registered')
        ->assertRedirect(route('register.verify'));

    expect(session(VerifyOtp::PENDING_SESSION_KEY))->toBe(['phone' => '+919876543210', 'user_id' => null])
        ->and($this->sms->accountExistsNotices)->toBe(['+919876543210']);

    // The code screen looks the same, and no code works.
    Livewire::test(VerifyOtp::class)
        ->assertSee('+91 98•••••210')
        ->set('code', '123456')
        ->call('verify')
        ->assertHasErrors(['code'])
        ->assertSee('incorrect or has expired');

    expect(auth('web')->check())->toBeFalse();
});

it('clears the password from the form state after a failed submit', function (): void {
    config(['oppam.auth.registrations_per_ip_per_hour' => 0]);

    fillHero(Livewire::test(QuickRegister::class))
        ->call('register')
        ->assertHasErrors(['form.mobile'])
        ->assertSet('form.password', '');
});

it('does not let the browser turn off the hero DOB requirement', function (): void {
    expect(fn () => Livewire::test(QuickRegister::class)->set('form.withDob', false))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

// ---- /register ------------------------------------------------------------------------------

it('registers from /register without a DOB and splits the full name', function (): void {
    Livewire::test(Register::class)
        ->assertOk()
        ->assertSee('Register free')
        ->set('form.createdFor', CreatedFor::Daughter->value)
        ->assertSet('form.gender', Gender::Female->value)   // derived live
        ->set('form.name', 'Anjali Mary Thomas')
        ->set('form.mobile', '9876543210')
        ->set('form.password', TEST_MEMBER_PASSWORD)
        ->set('form.terms', true)
        ->call('register')
        ->assertHasNoErrors()
        ->assertRedirect(route('register.verify'));

    $profile = User::query()->sole()->profile;

    expect($profile->first_name)->toBe('Anjali')
        ->and($profile->last_name)->toBe('Mary Thomas')
        ->and($profile->dob)->toBeNull();
});

// ---- /verify-otp ----------------------------------------------------------------------------

function pendingRegistration(object $test): User
{
    fillHero(Livewire::test(QuickRegister::class))->call('register');

    return User::query()->sole();
}

it('sends people with nothing to verify back to /register', function (): void {
    Livewire::test(VerifyOtp::class)->assertRedirect(route('register'));
});

it('shows the masked number and verifies the code, then lands on the wizard', function (): void {
    $user = pendingRegistration($this);
    $code = $this->sms->lastCodeFor('+919876543210', OtpPurpose::Register);

    Livewire::test(VerifyOtp::class)
        ->assertSee('+91 98•••••210')
        ->assertDontSee('9876543210')
        ->set('code', $code)
        ->call('verify')
        ->assertHasNoErrors()
        ->assertRedirect(route('member.onboarding', ['step' => 1]));

    expect(auth('web')->id())->toBe($user->id)
        ->and(session(VerifyOtp::PENDING_SESSION_KEY))->toBeNull();
});

it('shows an error for a wrong or malformed code and stays signed out', function (string $code, string $message): void {
    pendingRegistration($this);

    Livewire::test(VerifyOtp::class)
        ->set('code', $code)
        ->call('verify')
        ->assertHasErrors(['code'])
        ->assertSee($message);

    expect(auth('web')->check())->toBeFalse();
})->with([
    'wrong' => ['000001', 'incorrect or has expired'],
    'malformed' => ['12ab', 'The code has 6 digits'],
]);

it('throttles resend inside 30 seconds and allows it after', function (): void {
    pendingRegistration($this);

    Livewire::test(VerifyOtp::class)
        ->call('resend')
        ->assertHasErrors(['resend']);

    $this->travel(31)->seconds();

    Livewire::test(VerifyOtp::class)
        ->call('resend')
        ->assertHasNoErrors()
        ->assertDispatched('otp-resent');

    expect($this->sms->countFor('+919876543210'))->toBe(2);
});

// ---- /login ---------------------------------------------------------------------------------

it('renders the template login with both buttons and loading states', function (): void {
    Livewire::test(Login::class)
        ->assertOk()
        ->assertSee('Welcome back')
        ->assertSee('Login with OTP')
        ->assertSeeHtml('wire:target="loginWithPassword"');
});

it('signs in with a password and lands on the wizard for a DRAFT profile', function (): void {
    $user = memberWithPhone(status: ProfileStatus::Draft);

    Livewire::test(Login::class)
        ->set('loginId', '9876543210')
        ->set('password', TEST_MEMBER_PASSWORD)
        ->call('loginWithPassword')
        ->assertRedirect(route('member.onboarding', ['step' => 1]));

    expect(auth('web')->id())->toBe($user->id);
});

it('R-M01-4: shows one generic error and clears the password', function (): void {
    memberWithPhone();

    Livewire::test(Login::class)
        ->set('loginId', '9876543210')
        ->set('password', 'wrong-pass1')
        ->call('loginWithPassword')
        ->assertHasErrors(['loginId'])
        ->assertSee('The login details you entered are incorrect.')
        ->assertSet('password', '');
});

it('R-M01-4: the OTP step looks the same for registered and unknown numbers', function (string $mobile): void {
    memberWithPhone('+919876543210');

    Livewire::test(Login::class)
        ->call('useOtp')
        ->set('mobile', $mobile)
        ->call('sendCode')
        ->assertHasNoErrors()
        ->assertSet('codeSent', true)
        ->assertSee('If this number is registered, we have sent a 6-digit code to it.');
})->with(['registered' => '9876543210', 'unknown' => '9000000009']);

it('signs in with the OTP', function (): void {
    $user = memberWithPhone('+919876543210');

    $component = Livewire::test(Login::class)->call('useOtp')->set('mobile', '9876543210')->call('sendCode');

    $component->set('code', $this->sms->lastCodeFor('+919876543210', OtpPurpose::Login))
        ->call('loginWithOtp')
        ->assertRedirect(route('home'));   // ACTIVE profile, dashboard not built yet (P2.3)

    expect(auth('web')->id())->toBe($user->id);
});

it('switches back to password mode and forgets the code step', function (): void {
    Livewire::test(Login::class)
        ->call('useOtp')->set('mobile', '9876543210')->call('sendCode')
        ->call('usePassword')
        ->assertSet('mode', Login::MODE_PASSWORD)
        ->assertSet('codeSent', false);
});

// ---- /forgot-password -----------------------------------------------------------------------

it('R-M01-4: shows the code step even for an unknown number', function (): void {
    Livewire::test(ForgotPassword::class)
        ->set('mobile', '9000000009')
        ->call('sendCode')
        ->assertHasNoErrors()
        ->assertSet('codeSent', true)
        ->assertSee('is registered, we have sent a 6-digit code');

    expect($this->sms->sent)->toBe([]);
});

it('resets the password with the code and signs in', function (): void {
    $user = memberWithPhone('+919876543210');

    $component = Livewire::test(ForgotPassword::class)->set('mobile', '9876543210')->call('sendCode');

    $component->set('code', $this->sms->lastCodeFor('+919876543210', OtpPurpose::PasswordReset))
        ->set('password', 'newpass2026')
        ->set('password_confirmation', 'newpass2026')
        ->call('resetPassword')
        ->assertHasNoErrors()
        ->assertRedirect(route('home'));

    expect(auth('web')->id())->toBe($user->id);
});

it('validates the new password before touching the code', function (): void {
    memberWithPhone('+919876543210');

    Livewire::test(ForgotPassword::class)
        ->set('mobile', '9876543210')->call('sendCode')
        ->set('code', '123456')
        ->set('password', 'short1')
        ->set('password_confirmation', 'different1')
        ->call('resetPassword')
        ->assertHasErrors(['password']);
});

// ---- Logout ---------------------------------------------------------------------------------

it('logs out', function (): void {
    $this->actingAs(memberWithPhone(), 'web');

    Livewire::test(LogoutButton::class)
        ->call('logout')
        ->assertRedirect(route('home'));

    $this->assertGuest('web');
});

it('logs out other devices and says so', function (): void {
    $user = memberWithPhone();
    $this->actingAs($user, 'web');

    Livewire::test(LogoutButton::class)
        ->call('logoutOtherDevices')
        ->assertSee('You have been logged out on all other devices.');

    expect($user->refresh()->session_epoch)->toBe(1)
        ->and(auth('web')->check())->toBeTrue();
});

it('refuses "log out other devices" for a guest', function (): void {
    Livewire::test(LogoutButton::class)
        ->call('logoutOtherDevices')
        ->assertForbidden();
});

// ---- Wizard placeholder ---------------------------------------------------------------------

it('clamps an out-of-range wizard step, guards step order and refuses a tampered step', function (): void {
    $this->actingAs(memberWithPhone(status: ProfileStatus::Draft), 'web');

    // Out-of-range steps are clamped, then the step-order guard (P1.2) sends the member to the
    // first unfinished step: this factory draft has step 1 done, so that is step 2.
    Livewire::test(Wizard::class, ['step' => 9])->assertRedirect(route('member.onboarding', ['step' => 2]));

    expect(fn () => Livewire::test(Wizard::class, ['step' => 1])->set('step', 3))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('R-M01-4: a failing SMS provider still shows the same "code sent" screen', function (): void {
    memberWithPhone('+919876543210');
    $this->sms->failing = true;

    Livewire::test(Login::class)
        ->call('useOtp')
        ->set('mobile', '9876543210')
        ->call('sendCode')
        ->assertHasNoErrors()
        ->assertSet('codeSent', true)
        ->assertSee('If this number is registered, we have sent a 6-digit code to it.')
        ->assertDontSee('could not send');
});
