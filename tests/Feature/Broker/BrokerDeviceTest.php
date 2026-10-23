<?php

declare(strict_types=1);

use App\Actions\Auth\LoginWithOtp;
use App\Actions\Auth\LoginWithPassword;
use App\Actions\Auth\RequestMemberOtp;
use App\Actions\Broker\VerifyBrokerDevice;
use App\Enums\BureauRole;
use App\Enums\OtpPurpose;
use App\Exceptions\Auth\OtpInvalid;
use App\Livewire\Broker\Auth\VerifyDevice;
use App\Models\AuditLog;
use App\Models\BrokerStaff;
use App\Models\TrustedDevice;
use App\ValueObjects\PhoneNumber;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
| P7.1 — §8.1 / B.18 "Owner and Manager must pass an OTP challenge on each new device": a password
| sign-in on an unknown device is held at /broker/verify-device until a BROKER_DEVICE code is
| proved; a sign-in with an OTP trusts the device by itself; other roles are never challenged.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    $this->sms = fakeSms();
});

function deviceRequest(string $device): Illuminate\Http\Request
{
    $request = requestWithSession();
    $request->cookies->set((string) config('oppam.auth.device_cookie'), $device);

    return $request;
}

it('a password sign-in records the login time but trusts no device; an OTP sign-in trusts it', function (): void {
    $owner = bureauLogin(BureauRole::Owner);
    $owner->forceFill(['password' => TEST_MEMBER_PASSWORD])->save();

    app(LoginWithPassword::class)->handle($owner->phone, TEST_MEMBER_PASSWORD, false, deviceRequest(Str::random(40)));
    expect(TrustedDevice::query()->count())->toBe(0)
        ->and(BrokerStaff::query()->where('user_id', $owner->id)->value('last_login_at'))->not->toBeNull();

    $phone = PhoneNumber::fromE164($owner->phone);
    app(RequestMemberOtp::class)->handle($phone, OtpPurpose::Login, '10.0.0.1');
    $device = Str::random(40);
    app(LoginWithOtp::class)->handle($phone, (string) $this->sms->lastCodeFor($owner->phone, OtpPurpose::Login), false, deviceRequest($device));

    expect(TrustedDevice::query()->where('user_id', $owner->id)->where('device_hash', hash('sha256', $device))->exists())->toBeTrue();
});

it('§8.1: the device code trusts THIS device only, and only for the login it was sent to', function (): void {
    $owner = bureauLogin(BureauRole::Owner);
    $device = Str::random(40);

    app(VerifyBrokerDevice::class)->send($owner, deviceRequest($device));
    $code = (string) $this->sms->lastCodeFor($owner->phone, OtpPurpose::BrokerDevice);

    expect(fn () => app(VerifyBrokerDevice::class)->verify($owner, '000000', deviceRequest($device)))->toThrow(OtpInvalid::class);
    // The same code, on ANOTHER login, is no good either.
    expect(fn () => app(VerifyBrokerDevice::class)->verify(bureauLogin(BureauRole::Manager), $code, deviceRequest($device)))->toThrow(OtpInvalid::class);

    app(VerifyBrokerDevice::class)->verify($owner, $code, deviceRequest($device));

    expect(TrustedDevice::query()->where('user_id', $owner->id)->pluck('device_hash')->all())->toBe([hash('sha256', $device)])
        ->and(AuditLog::query()->where('action', 'broker.device_verified')->count())->toBe(1);

    // A LOGIN code never satisfies the device check (codes are per purpose).
    $other = bureauLogin(BureauRole::Manager);
    app(RequestMemberOtp::class)->handle(PhoneNumber::fromE164($other->phone), OtpPurpose::Login, '10.0.0.2');
    expect(fn () => app(VerifyBrokerDevice::class)->verify($other, (string) $this->sms->lastCodeFor($other->phone, OtpPurpose::Login), deviceRequest(Str::random(40))))
        ->toThrow(OtpInvalid::class);
});

it('the device page: untrusted Owner is held there, confirms, then reaches the page they wanted', function (): void {
    $owner = bureauLogin(BureauRole::Owner);
    $device = Str::random(40);
    $this->actingAs($owner, 'web')->withCookie((string) config('oppam.auth.device_cookie'), $device);

    $this->get(brokerUrl('/kyc'))->assertRedirect(brokerUrl('/verify-device'));
    $this->get(brokerUrl('/verify-device'))->assertOk()->assertSee('Confirm this device');

    request()->cookies->set((string) config('oppam.auth.device_cookie'), $device);
    Livewire::withCookie((string) config('oppam.auth.device_cookie'), $device)->test(VerifyDevice::class)
        ->assertSee('Send code')
        ->call('send')
        ->assertSet('sent', true)
        ->set('code', '12')
        ->call('verify')->assertHasErrors('code')
        ->set('code', (string) $this->sms->lastCodeFor($owner->phone, OtpPurpose::BrokerDevice))
        ->call('verify')
        ->assertHasNoErrors()
        ->assertRedirect();

    $this->get(brokerUrl('/kyc'))->assertOk();
});

it('rule 10: a second device code within the resend gap is refused with the wait time; one SMS only', function (): void {
    $owner = bureauLogin(BureauRole::Owner);
    $this->actingAs($owner, 'web');

    Livewire::test(VerifyDevice::class)
        ->call('send')->assertSet('sent', true)->assertHasNoErrors()
        ->call('send')->assertHasErrors('code');

    expect(collect($this->sms->sent)->filter(fn (array $sms): bool => ($sms['purpose'] ?? null) === OtpPurpose::BrokerDevice)->count())->toBe(1);
});

it('no SMS is sent just by opening the device page (only on request)', function (): void {
    $owner = bureauLogin(BureauRole::Owner);
    $this->actingAs($owner, 'web');

    $this->get(brokerUrl('/verify-device'))->assertOk();

    expect($this->sms->sent)->toBe([]);
});
