<?php

declare(strict_types=1);

use App\Actions\Auth\RegisterMember;
use App\Actions\Broker\EnterReferralCode;
use App\Domain\Broker\BrokerCodeLookup;
use App\Enums\BrokerStatus;
use App\Enums\ReferralSource;
use App\Enums\ReferralStatus;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Livewire\Member\Auth\Register;
use App\Livewire\Member\Onboarding\Wizard;
use App\Models\Broker;
use App\Models\BrokerReferral;
use App\Models\Profile;
use App\Models\User;
use App\Services\Admin\Impersonation;
use Livewire\Livewire;

/*
| P7.2 — ResolveBrokerCode (PRD §11A B.5): at registration and wizard step 1, typed code beats the
| `?ref=` cookie (R-M13-9), codes normalised (R-M13-2), an unknown / inactive code never blocks and
| is kept raw for A07 (R-M13-1), a verified attribution is permanent for the member (R-M13-3), and
| the public validate endpoint shows name + district only.
*/

beforeEach(function (): void {
    $this->sms = fakeSms();
    seedMasters();
});

function registerWithCode(?string $typed, ?string $cookie = null, string $mobile = '9876543210'): Profile
{
    $user = app(RegisterMember::class)->handle(registrationData([
        'phone' => App\ValueObjects\PhoneNumber::fromParts('91', $mobile),
        'email' => null,
        'referralCode' => $typed,
        'referralCookie' => $cookie,
    ]), '10.0.0.1')->user;
    expect($user)->not->toBeNull();

    return $user->profile;
}

it('FOUND + ACTIVE: the profile is attributed (verified) and a PENDING referral row is opened', function (): void {
    $broker = Broker::factory()->create();

    $profile = registerWithCode($broker->code);

    expect($profile->broker_id)->toBe($broker->id)
        ->and($profile->broker_verified)->toBeTrue()
        ->and($profile->broker_code_raw)->toBe($broker->code)
        ->and(BrokerReferral::query()->sole())
        ->broker_id->toBe($broker->id)
        ->source->toBe(ReferralSource::Referral)
        ->status->toBe(ReferralStatus::Pending);
});

it('R-M13-2 / B.19: "brk1042" and "BRK 1042" are normalised before lookup', function (string $typed): void {
    $broker = Broker::factory()->create(['code' => 'BRK1042']);

    expect(registerWithCode($typed)->broker_id)->toBe($broker->id);
})->with(['lower case' => 'brk1042', 'spaces' => ' BRK 1042 ', 'mixed' => 'Brk 10 42', 'hyphen' => 'BRK-1042']);

it('R-M13-1: an unknown code registers fine — raw code kept, unverified, no referral row', function (): void {
    $profile = registerWithCode('BRK9999');

    expect($profile->broker_id)->toBeNull()
        ->and($profile->broker_verified)->toBeFalse()
        ->and($profile->broker_code_raw)->toBe('BRK9999')
        ->and(BrokerReferral::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(1);
});

it('R-M13-1 / B.19: the code of a bureau that is not ACTIVE is flagged, not attributed', function (BrokerStatus $status): void {
    $broker = Broker::factory()->create(['status' => $status]);

    $profile = registerWithCode($broker->code);

    expect($profile->broker_id)->toBeNull()->and($profile->broker_code_raw)->toBe($broker->code)
        ->and(BrokerReferral::query()->count())->toBe(0);
})->with([BrokerStatus::Applied, BrokerStatus::KycPending, BrokerStatus::Suspended, BrokerStatus::Deactivated, BrokerStatus::Rejected]);

it('R-M13-1: junk in the code field never blocks registration (ignored)', function (): void {
    $profile = registerWithCode(str_repeat('X', 25));

    expect($profile->broker_code_raw)->toBeNull()->and(User::query()->count())->toBe(1);
});

it('R-M13-9: the `?ref=` cookie attributes when nothing was typed; a typed code wins over it', function (): void {
    $cookieBroker = Broker::factory()->create();
    $typedBroker = Broker::factory()->create();

    expect(registerWithCode(null, $cookieBroker->code)->broker_id)->toBe($cookieBroker->id)
        ->and(registerWithCode($typedBroker->code, $cookieBroker->code, '9876543211')->broker_id)->toBe($typedBroker->id);
});

it('releasing an unproved sign-up removes its referral row too (it never counted)', function (): void {
    $broker = Broker::factory()->create();
    registerWithCode($broker->code);
    $this->travel(31)->seconds();

    $second = registerWithCode(null);   // the same number again: the unproved first sign-up is released

    expect(BrokerReferral::query()->count())->toBe(0)->and($second->broker_id)->toBeNull();
});

it('/register pre-fills the code from the first-touch cookie and shows the bureau name + district', function (): void {
    $broker = Broker::factory()->create(['code' => 'BRK1042', 'bureau_name' => 'Malabar Marriage Bureau', 'district_id' => keralaDistrictId()]);

    Livewire::withCookie((string) config('oppam.auth.referral_cookie'), 'brk1042')
        ->test(Register::class)
        ->assertSet('form.referralCode', 'BRK1042')
        ->assertSee('Malabar Marriage Bureau, Ernakulam')
        ->set('form.referralCode', 'BRK0000')
        ->assertSee('We couldn’t find this code. You can still continue')
        ->assertDontSee('Malabar Marriage Bureau')
        ->set('form.referralCode', 'BRK<1>')
        ->call('register')
        ->assertHasNoErrors(['form.referralCode'])   // R-M13-1: never blocks — ignored
        ->set('form.referralCode', str_repeat('A', 31))
        ->call('register')
        ->assertHasErrors(['form.referralCode' => 'max']);

    expect($broker->refresh()->phone)->not->toBeNull();
});

it('the ?ref= link sets the first-touch cookie and the /register page carries the code', function (): void {
    Broker::factory()->create(['code' => 'BRK1042', 'bureau_name' => 'Malabar Marriage Bureau']);

    $this->get(memberUrl('/?ref=brk 1042'))->assertCookie((string) config('oppam.auth.referral_cookie'), 'BRK1042');
    // The field is filled by Livewire from its snapshot; the hint under it proves the pre-fill.
    $this->withCookie((string) config('oppam.auth.referral_cookie'), 'BRK1042')
        ->get(memberUrl('/register'))->assertOk()->assertSee('Malabar Marriage Bureau');
});

it('wizard step 1: a member without an attribution may enter a code; "Continue" attributes like registration', function (): void {
    $broker = Broker::factory()->create();
    $member = memberThroughStep(1);
    $this->actingAs($member);

    $wizard = Livewire::test(Wizard::class, ['step' => 1])
        ->assertSee('Bureau referral code (optional)')
        ->set('referralCode', strtolower($broker->code))
        ->call('autosave');   // autosave never attributes: a half-typed code must not stick (R-M13-3)
    expect($member->profile->refresh()->broker_id)->toBeNull();

    $wizard->call('next')->assertHasNoErrors();

    expect($member->profile->refresh()->broker_id)->toBe($broker->id)
        ->and(BrokerReferral::query()->where('profile_id', $member->profile->id)->exists())->toBeTrue();
});

it('wizard step 1: a support session sees no referral field and its save never touches attribution', function (): void {
    $broker = Broker::factory()->create();
    $member = memberThroughStep(1);
    $this->actingAs($member);
    chatImpersonate($member);

    Livewire::test(Wizard::class, ['step' => 1])
        ->assertDontSee('Bureau referral code (optional)')
        ->set('referralCode', $broker->code)
        ->call('next')
        ->assertHasNoErrors();

    expect($member->profile->refresh()->broker_id)->toBeNull();
});

it('wizard step 1: a mistyped raw code can be corrected before the profile is published', function (): void {
    $broker = Broker::factory()->create();
    $member = memberThroughStep(1);
    $member->profile->forceFill(['broker_code_raw' => 'BRK0000'])->save();
    $this->actingAs($member);

    Livewire::test(Wizard::class, ['step' => 1])->assertSet('referralCode', 'BRK0000')
        ->set('referralCode', $broker->code)->call('next');

    expect($member->profile->refresh()->broker_id)->toBe($broker->id)->and($member->profile->broker_code_raw)->toBe($broker->code);
});

it('R-M13-3: a verified attribution is permanent — step 1 shows it read-only and the Action ignores a new code', function (): void {
    $first = Broker::factory()->create(['bureau_name' => 'First Bureau']);
    $other = Broker::factory()->create();
    $member = draftMember();
    $member->profile->forceFill(['broker_id' => $first->id, 'broker_code_raw' => $first->code, 'broker_verified' => true])->save();
    $this->actingAs($member);

    Livewire::test(Wizard::class, ['step' => 1])
        ->assertSee('Referred by First Bureau')
        ->assertDontSee('Bureau referral code (optional)')
        ->set('referralCode', $other->code)->call('autosave');
    app(EnterReferralCode::class)->handle($member, $member->profile->refresh(), $other->code);

    expect($member->profile->refresh()->broker_id)->toBe($first->id);
});

it('R-M13-3: once the profile has been published the member can no longer add a code', function (): void {
    $broker = Broker::factory()->create();
    $member = memberWithPhone('+919811100077');   // ACTIVE, published

    app(EnterReferralCode::class)->handle($member, $member->profile, $broker->code);

    expect($member->profile->refresh()->broker_id)->toBeNull()->and($member->profile->broker_code_raw)->toBeNull();
});

it('security: only the owner may enter a code for a profile; a support session may not', function (): void {
    $broker = Broker::factory()->create();
    $owner = draftMember();
    $other = memberWithPhone('+919811100078');

    expect(fn () => app(EnterReferralCode::class)->handle($other, $owner->profile, $broker->code))
        ->toThrow(Illuminate\Auth\Access\AuthorizationException::class);

    session()->put(Impersonation::SESSION_KEY, 'x');
    expect(fn () => app(EnterReferralCode::class)->handle($owner, $owner->profile, $broker->code))
        ->toThrow(ImpersonationRestricted::class);
    expect($owner->profile->refresh()->broker_id)->toBeNull();
});

it('B.5 validate endpoint: {valid, name, district} only — never phone, email or owner', function (): void {
    Broker::factory()->create(['code' => 'BRK1042', 'name' => 'Ramesh Owner', 'bureau_name' => 'Malabar Bureau', 'email' => 'owner@bureau.test', 'district_id' => keralaDistrictId()]);

    $response = $this->getJson(memberUrl('/broker/validate?code=brk 1042'))->assertOk()
        ->assertExactJson(['valid' => true, 'name' => 'Malabar Bureau', 'district' => 'Ernakulam']);

    expect($response->getContent())->not->toContain('owner@bureau.test')->not->toContain('Ramesh')->not->toContain('+91');
});

it('validate: unknown, inactive and empty codes all answer the same {valid: false}', function (?string $code): void {
    Broker::factory()->create(['code' => 'BRK2000', 'status' => BrokerStatus::Suspended]);

    $this->getJson(memberUrl('/broker/validate'.($code === null ? '' : '?code='.$code)))->assertOk()
        ->assertExactJson(['valid' => false, 'name' => null, 'district' => null]);
})->with(['unknown' => 'BRK9999', 'inactive' => 'BRK2000', 'missing' => null, 'junk' => '<script>']);

it('rule 10: the validate endpoint is rate-limited per visitor', function (): void {
    foreach (range(1, 30) as $ignored) {
        $this->getJson(memberUrl('/broker/validate?code=BRK1'))->assertOk();
    }

    $this->getJson(memberUrl('/broker/validate?code=BRK1'))->assertStatus(429);
});

it('BrokerCodeLookup::normalise refuses what can’t be a code', function (?string $raw, ?string $expected): void {
    expect(BrokerCodeLookup::normalise($raw))->toBe($expected);
})->with([
    [' brk 1042 ', 'BRK1042'], ['', null], [null, null], ['BRK-1042', 'BRK1042'], ['BRK_1042', null], ['BRK<1>', null],
    [str_repeat('A', 21), null], [str_repeat('A', 20), str_repeat('A', 20)],
]);
