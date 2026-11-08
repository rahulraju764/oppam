<?php

declare(strict_types=1);

use App\Actions\Auth\ChangePhone;
use App\Actions\Auth\SignInMember;
use App\Enums\LoginMethod;
use App\Enums\OtpPurpose;
use App\Livewire\Member\Auth\ForgotPassword;
use App\Livewire\Member\Auth\ResetPasswordEmail;
use App\Livewire\Member\Settings\AccountSettings;
use App\Livewire\Member\Settings\VerifyEmail;
use App\Models\EmailChangeRequest;
use App\Models\LoginEvent;
use App\Models\User;
use App\Notifications\Auth\PasswordResetLink;
use Database\Seeders\PlansSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P8.2b — the Account tab and its pages (PRD M14 / M01, §8.2): render, each card's steps,
| validation, refusals shown inline, loading markup, the devices list (own rows only), the email
| confirmation page, the email-link reset page and the "email me a link" mode of Forgot password.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    $this->sms = fakeSms();
    Notification::fake();
});

function acMember(): User
{
    $member = memberWithPhone('+919876544001');
    $member->forceFill(['email' => 'ac@example.com', 'email_verified_at' => now()])->save();

    return $member->refresh();
}

it('Account tab: renders the four cards with masked number, verified email, loading buttons and own devices only', function (): void {
    $member = acMember();
    $other = memberWithPhone('+919876544009');
    LoginEvent::query()->forceCreate(['user_id' => $member->id, 'method' => LoginMethod::Password, 'device_hash' => hash('sha256', str_repeat('a', 40)),
        'ip_address' => '203.0.113.45', 'user_agent' => 'Mozilla/5.0 (Linux; Android 14) Chrome/124.0 Mobile Safari/537.36']);
    LoginEvent::query()->forceCreate(['user_id' => $other->id, 'method' => LoginMethod::Password, 'device_hash' => hash('sha256', str_repeat('b', 40)),
        'ip_address' => '198.51.100.7', 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Firefox/125.0']);
    $this->actingAs($member, 'web');
    Model::preventLazyLoading();

    Livewire::withoutLazyLoading()->test(AccountSettings::class)
        ->assertSee('Mobile number')->assertDontSee('+919876544001')->assertSee('Verified')
        ->assertSee('Chrome on Android')->assertSee('203.0.113.*')->assertDontSee('203.0.113.45')
        ->assertDontSee('Firefox on Windows')->assertDontSee('198.51.100')
        ->assertSeeHtml('wire:target="changePassword"')->assertSeeHtml('wire:target="startPhoneChange"')
        ->assertSeeHtml('wire:target="signOutOtherDevices"')->assertSeeHtml('wire:target="sendEmailLink"');
});

it('Account tab: change mobile through both codes on screen', function (): void {
    $member = acMember();
    $this->actingAs($member, 'web')->withSession([SignInMember::EPOCH_SESSION_KEY => $member->session_epoch]);

    $page = Livewire::withoutLazyLoading()->test(AccountSettings::class)
        ->call('startPhoneChange')->assertDispatched('toast', type: 'success')->assertSee('Step 1 of 2');
    $page->set('oldCode', '12')->call('confirmOldCode')->assertHasErrors(['oldCode']);
    $page->set('oldCode', (string) $this->sms->lastCodeFor('+919876544001', OtpPurpose::ChangePhone))->call('confirmOldCode')
        ->assertHasNoErrors()->assertSee('Step 2 of 2');
    $page->set('newMobile', '12')->call('sendNewCode')->assertHasErrors(['newMobile']);
    $page->set('newMobile', '9876544002')->call('sendNewCode')->assertHasNoErrors()->assertSee('Change my number');
    $page->set('newCode', (string) $this->sms->lastCodeFor('+919876544002', OtpPurpose::ChangePhone))->call('confirmNewCode')
        ->assertHasNoErrors()->assertDispatched('toast', type: 'success');

    expect($member->refresh()->phone)->toBe('+919876544002');
});

it('Account tab: a wrong code shows under the field; Cancel forgets the progress', function (): void {
    $member = acMember();
    $this->actingAs($member, 'web');

    $page = Livewire::withoutLazyLoading()->test(AccountSettings::class)->call('startPhoneChange');
    $right = (string) $this->sms->lastCodeFor('+919876544001', OtpPurpose::ChangePhone);
    $page->set('oldCode', $right === '000000' ? '111111' : '000000')->call('confirmOldCode')->assertHasErrors(['oldCode']);
    $page->set('oldCode', $right)->call('confirmOldCode')->assertSee('Step 2 of 2');

    $page->call('cancelPhoneChange')->assertSee('Change mobile number');
    expect(session(ChangePhone::SESSION_KEY))->toBeNull();
});

it('Account tab: email link, validation, and Remove', function (): void {
    $member = acMember();
    $this->actingAs($member, 'web');

    Livewire::withoutLazyLoading()->test(AccountSettings::class)
        ->set('email', 'not-an-email')->call('sendEmailLink')->assertHasErrors(['email'])
        ->set('email', 'new@example.com')->call('sendEmailLink')->assertHasNoErrors()->assertDispatched('toast', type: 'success')
        ->call('removeEmail')->assertDispatched('toast', type: 'success');

    expect(EmailChangeRequest::query()->where('user_id', $member->id)->count())->toBe(1)
        ->and($member->refresh()->email)->toBeNull();
});

it('Account tab: change password — wrong current password inline, weak new one invalid, then success', function (): void {
    $member = acMember();
    $this->actingAs($member, 'web');

    Livewire::withoutLazyLoading()->test(AccountSettings::class)
        ->set('currentPassword', 'nope-wrong1')->set('password', 'NewPass2026')->set('password_confirmation', 'NewPass2026')
        ->call('changePassword')->assertHasErrors(['currentPassword'])
        ->set('currentPassword', TEST_MEMBER_PASSWORD)->set('password', 'short')->set('password_confirmation', 'short')
        ->call('changePassword')->assertHasErrors(['password'])
        ->set('currentPassword', TEST_MEMBER_PASSWORD)->set('password', 'NewPass2026')->set('password_confirmation', 'NewPass2026')
        ->call('changePassword')->assertHasNoErrors()->assertSet('password', '')->assertSet('currentPassword', '');

    expect(Hash::check('NewPass2026', $member->refresh()->password))->toBeTrue();
});

it('Account tab: sign out all other devices', function (): void {
    $member = acMember();
    $epoch = $member->session_epoch;
    $this->actingAs($member, 'web');

    Livewire::withoutLazyLoading()->test(AccountSettings::class)->call('signOutOtherDevices')->assertDispatched('toast', type: 'success');

    expect($member->refresh()->session_epoch)->toBe($epoch + 1);
});

it('Account tab: a support session is refused with a message', function (): void {
    $member = acMember();
    $this->actingAs($member, 'web');
    chatImpersonate($member);

    Livewire::withoutLazyLoading()->test(AccountSettings::class)->call('startPhoneChange')->assertDispatched('toast', type: 'danger');
    expect($this->sms->lastCodeFor('+919876544001', OtpPurpose::ChangePhone))->toBeNull();
});

it('email confirmation page: opening it changes nothing; the button confirms; the token can\'t be swapped', function (): void {
    $member = acMember();
    $token = str_repeat('t', 64);
    EmailChangeRequest::factory()->forToken($token)->create(['user_id' => $member->id, 'email' => 'fresh@example.com']);

    $this->get(route('member.email.verify', ['token' => $token]))->assertOk()->assertSee('Confirm my email address');
    expect($member->refresh()->email)->toBe('ac@example.com');

    $page = Livewire::test(VerifyEmail::class, ['token' => $token]);
    expect(fn () => $page->set('token', str_repeat('u', 64)))->toThrow(CannotUpdateLockedPropertyException::class);
    $page->call('confirm')->assertSet('done', true)->assertSee('your email address is confirmed');
    expect($member->refresh()->email)->toBe('fresh@example.com');

    Livewire::test(VerifyEmail::class, ['token' => $token])->call('confirm')->assertSet('done', false)->assertSee('expired or was already used');
});

it('forgot password: "email me a link" shows the same answer for any address', function (): void {
    acMember();

    Livewire::test(ForgotPassword::class)->call('useEmail')
        ->set('email', 'bad')->call('sendEmailLink')->assertHasErrors(['email'])
        ->set('email', 'nobody@example.com')->call('sendEmailLink')->assertSet('emailSent', true)->assertSee('a reset link is on its way');
    Notification::assertSentOnDemandTimes(PasswordResetLink::class, 0);

    Livewire::test(ForgotPassword::class)->call('useEmail')
        ->set('email', 'ac@example.com')->call('sendEmailLink')->assertSet('emailSent', true)->assertSee('a reset link is on its way');
    Notification::assertSentOnDemandTimes(PasswordResetLink::class, 1);
});

it('reset page: the link sets a new password and signs in; a bad link shows an error; the token is locked', function (): void {
    $member = acMember();
    app(App\Actions\Auth\SendPasswordResetLink::class)->handle('ac@example.com', '10.1.1.1');
    $token = '';
    Notification::assertSentOnDemand(PasswordResetLink::class, function (PasswordResetLink $n) use (&$token): bool {
        $token = basename($n->url);

        return true;
    });

    $this->get(route('password.reset.email', ['token' => $token]))->assertOk()->assertSee('Choose a new password');

    $page = Livewire::test(ResetPasswordEmail::class, ['token' => $token]);
    expect(fn () => $page->set('token', str_repeat('z', 64)))->toThrow(CannotUpdateLockedPropertyException::class);
    $page->set('password', 'FreshPass2026')->set('password_confirmation', 'FreshPass2026')->call('resetPassword')->assertRedirect();
    expect(Hash::check('FreshPass2026', $member->refresh()->password))->toBeTrue();

    auth('web')->logout();
    Livewire::test(ResetPasswordEmail::class, ['token' => $token])
        ->set('password', 'OtherPass2026')->set('password_confirmation', 'OtherPass2026')->call('resetPassword')
        ->assertHasErrors(['password'])->assertNoRedirect();
});
