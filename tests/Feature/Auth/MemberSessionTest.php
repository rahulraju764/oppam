<?php

declare(strict_types=1);

use App\Actions\Auth\LogoutOtherDevices;
use App\Actions\Auth\SignInMember;
use App\Enums\LoginMethod;
use App\Enums\ProfileStatus;
use App\Enums\UserStatus;
use App\Models\LoginEvent;
use App\Models\User;
use App\Notifications\Auth\NewDeviceSignIn;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Notification;

/*
| P1.1 — member session rules: verified.phone, profile.onboarded, suspended accounts signed out
| (R-M01-5), "log out other devices", new-device alert, ?ref= first-touch cookie (R-M13-9).
*/

beforeEach(function (): void {
    fakeSms();
    $this->seed(PlansSeeder::class);   // the member header shows the plan name
});

function signedIn(object $test, User $user, ?int $epoch = null): object
{
    return $test->actingAs($user, 'web')->withSession([SignInMember::EPOCH_SESSION_KEY => $epoch ?? $user->session_epoch]);
}

it('sends guests to the member login page', function (): void {
    $this->get(memberUrl('/onboarding/1'))->assertRedirect(route('login'));
});

it('lets a verified member with a DRAFT profile reach the wizard', function (): void {
    $user = memberWithPhone(status: ProfileStatus::Draft);

    signedIn($this, $user)->get(memberUrl('/onboarding/1'))
        ->assertOk()
        ->assertSee($user->profile->code);
});

it('keeps a submitted profile out of the wizard: it waits on the "under review" page (R-M02-2)', function (): void {
    $user = memberWithPhone(status: ProfileStatus::PendingReview);

    signedIn($this, $user)->get(memberUrl('/onboarding/1'))->assertRedirect(route('member.onboarding.submitted'));
});

it('verified.phone: an unverified account is signed out, never let through', function (): void {
    $user = User::factory()->unverified()->create();

    signedIn($this, $user)->get(memberUrl('/onboarding/1'))->assertRedirect(route('login'));

    $this->assertGuest('web');
});

it('404s broker logins on member pages (they own no profile)', function (): void {
    $broker = User::factory()->broker()->create();

    signedIn($this, $broker)->get(memberUrl('/onboarding/1'))->assertNotFound();
});

it('R-M01-5: signs a suspended member out on the next request', function (UserStatus $status): void {
    $user = memberWithPhone(status: ProfileStatus::Draft);
    $user->forceFill(['status' => $status])->save();

    signedIn($this, $user)->get(memberUrl('/onboarding/1'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status');

    $this->assertGuest('web');
})->with([UserStatus::Suspended, UserStatus::Banned, UserStatus::Deleted]);

it('sends a signed-in member away from login and register', function (): void {
    $user = memberWithPhone(status: ProfileStatus::Draft);

    signedIn($this, $user)->get(memberUrl('/login'))->assertRedirect(route('member.onboarding', ['step' => 1]));
    signedIn($this, $user)->get(memberUrl('/register'))->assertRedirect(route('member.onboarding', ['step' => 1]));
});

it('log out other devices: ends sessions stamped with the old epoch, keeps this one', function (): void {
    $user = memberWithPhone(status: ProfileStatus::Draft);
    $oldEpoch = $user->session_epoch;
    $oldToken = $user->getRememberToken();

    $this->actingAs($user, 'web');
    app(LogoutOtherDevices::class)->handle($user, requestWithSession());

    expect($user->refresh()->session_epoch)->toBe($oldEpoch + 1)
        ->and($user->getRememberToken())->not->toBe($oldToken)
        ->and(session(SignInMember::EPOCH_SESSION_KEY))->toBe($oldEpoch + 1);

    // This device (new epoch) stays in; another device (old epoch) is signed out.
    signedIn($this, $user, $oldEpoch + 1)->get(memberUrl('/onboarding/1'))->assertOk();
    signedIn($this, $user, $oldEpoch)->get(memberUrl('/onboarding/1'))->assertRedirect(route('login'));
});

it('adopts the current epoch for a session rebuilt from a "stay logged in" cookie', function (): void {
    $user = memberWithPhone(status: ProfileStatus::Draft);

    $this->actingAs($user, 'web')->get(memberUrl('/onboarding/1'))->assertOk()
        ->assertSessionHas(SignInMember::EPOCH_SESSION_KEY, $user->session_epoch);
});

it('emails a verified address when a known account signs in from a new device', function (): void {
    Notification::fake();
    $user = memberWithPhone();
    $user->forceFill(['email' => 'anjali@example.com', 'email_verified_at' => now()])->save();

    app(SignInMember::class)->handle($user, LoginMethod::Password, false, requestWithSession());   // first ever: no alert
    Notification::assertNothingSent();

    app(SignInMember::class)->handle($user, LoginMethod::Password, false, requestWithSession());   // no device cookie → new device
    Notification::assertSentTo($user, NewDeviceSignIn::class);
    expect(LoginEvent::query()->count())->toBe(2);
});

it('never sends the new-device alert to an unverified email', function (): void {
    Notification::fake();
    $user = memberWithPhone();
    $user->forceFill(['email' => 'someone@example.com', 'email_verified_at' => null])->save();

    app(SignInMember::class)->handle($user, LoginMethod::Password, false, requestWithSession());
    app(SignInMember::class)->handle($user, LoginMethod::Password, false, requestWithSession());

    Notification::assertNothingSent();
});

it('recognises a returning device by its cookie (no alert)', function (): void {
    Notification::fake();
    $user = memberWithPhone();
    $user->forceFill(['email' => 'anjali@example.com', 'email_verified_at' => now()])->save();
    $deviceId = str_repeat('a', 40);

    foreach (range(1, 2) as $_) {
        $request = requestWithSession();
        $request->cookies->set(config('oppam.auth.device_cookie'), $deviceId);
        app(SignInMember::class)->handle($user, LoginMethod::Password, false, $request);
    }

    Notification::assertNothingSent();
    expect(LoginEvent::query()->distinct()->count('device_hash'))->toBe(1)
        ->and(LoginEvent::query()->first()?->device_hash)->toBe(hash('sha256', $deviceId));
});

it('R-M13-9: sets a normalised 30-day first-touch referral cookie from ?ref=', function (): void {
    $response = $this->get(memberUrl('/?ref=brk 1042'));

    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === config('oppam.auth.referral_cookie'));

    expect($cookie)->not->toBeNull()
        ->and(decrypt($cookie->getValue(), false))->toEndWith('BRK1042')
        ->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(29)->getTimestamp())
        ->and($cookie->isHttpOnly())->toBeTrue();
});

it('R-M13-9: never overwrites an existing referral cookie (first touch wins)', function (): void {
    $response = $this->withCookie(config('oppam.auth.referral_cookie'), 'BRK1000')->get(memberUrl('/?ref=BRK2000'));

    expect(collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === config('oppam.auth.referral_cookie')))->toBeNull();
});

it('ignores malformed referral codes', function (string $ref): void {
    $response = $this->get(memberUrl('/?ref='.urlencode($ref)));

    expect(collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === config('oppam.auth.referral_cookie')))->toBeNull();
})->with(['<script>', 'BRK-1042', str_repeat('A', 21)]);

it('refuses an old "stay logged in" cookie after log out other devices', function (): void {
    $user = memberWithPhone(status: ProfileStatus::Draft);
    $user->forceFill(['remember_token' => 'old-token-'.str_repeat('x', 50)])->save();
    $recaller = auth('web')->getRecallerName();
    $cookie = $user->id.'|'.$user->getRememberToken().'|'.$user->getAuthPassword();

    // The old cookie works before…
    $this->withCookie($recaller, $cookie)->get(memberUrl('/onboarding/1'))->assertOk();
    auth('web')->logout();
    $this->flushSession();

    app(LogoutOtherDevices::class)->handle($user->refresh(), requestWithSession());

    // …and not after.
    $this->withCookie($recaller, $cookie)->get(memberUrl('/onboarding/1'))->assertRedirect(route('login'));
    $this->assertGuest('web');
});

it('keeps this device\'s "stay logged in" when logging out the others', function (): void {
    $user = memberWithPhone(status: ProfileStatus::Draft);
    $request = requestWithSession();
    $request->cookies->set(auth('web')->getRecallerName(), 'present');
    $this->actingAs($user, 'web');

    app(LogoutOtherDevices::class)->handle($user, $request);

    $reissued = collect(app('cookie')->getQueuedCookies())->first(fn ($c) => $c->getName() === auth('web')->getRecallerName());

    expect($reissued)->not->toBeNull()
        ->and($reissued->getValue())->toContain($user->refresh()->getRememberToken());
});
