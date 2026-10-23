<?php

declare(strict_types=1);

use App\Actions\Auth\CompleteRegistration;
use App\Actions\Broker\ApplyAsBroker;
use App\Data\Broker\BrokerApplicationData;
use App\Domain\Broker\PanNumber;
use App\Enums\BrokerKycStatus;
use App\Enums\BrokerStatus;
use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Enums\Flag;
use App\Enums\OtpPurpose;
use App\Enums\UserRole;
use App\Exceptions\Auth\RegistrationFailed;
use App\Livewire\Broker\Apply;
use App\Models\AuditLog;
use App\Models\Broker;
use App\Models\BrokerStaff;
use App\Models\Masters\District;
use App\Models\TrustedDevice;
use App\Models\User;
use App\ValueObjects\PhoneNumber;
use Database\Seeders\PlansSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| P7.1 — /broker/apply (PRD §11A B.3, B.11): behind broker.self_apply; creates the APPLIED bureau
| (system code, R-A07-1), its BROKER login and the INVITED OWNER row; the mobile is proved on the
| normal /verify-otp screen; one mobile = one login (R-M13-22) without revealing taken numbers;
| one PAN = one bureau.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    flagOn(Flag::BrokerSelfApply);
    $this->sms = fakeSms();
});

function brokerApplication(array $overrides = []): BrokerApplicationData
{
    return new BrokerApplicationData(...array_merge([
        'name' => 'Rajan Pillai',
        'bureauName' => 'Kochi Marriage Bureau',
        'phone' => PhoneNumber::fromParts('91', '9847012345'),
        'email' => 'rajan@example.com',
        'districtId' => (int) District::query()->value('id'),
        'pan' => TEST_PAN,
        'password' => TEST_MEMBER_PASSWORD,
    ], $overrides));
}

it('B.3: an application creates the APPLIED bureau, its BROKER login (mobile unverified) and the INVITED owner row', function (): void {
    $result = app(ApplyAsBroker::class)->handle(brokerApplication(), '10.0.0.5');

    $user = $result->user;
    $broker = Broker::query()->sole();
    $owner = BrokerStaff::query()->sole();

    expect($user?->role)->toBe(UserRole::Broker)
        ->and($user?->phone_verified_at)->toBeNull()
        ->and($broker->code)->toMatch('/^BRK\d{4,}$/')
        ->and($broker->status)->toBe(BrokerStatus::Applied)
        ->and($broker->kyc_status)->toBe(BrokerKycStatus::NotSubmitted)
        ->and($broker->pan_last4)->toBe('234F')
        ->and($broker->pan_hash)->toBe(PanNumber::hash(TEST_PAN))
        ->and($broker->commission_bps)->toBe(1000)
        ->and($owner->role)->toBe(BureauRole::Owner)
        ->and($owner->status)->toBe(BureauStaffStatus::Invited)
        ->and($owner->user_id)->toBe($user?->id)
        ->and($this->sms->lastCodeFor('+919847012345', OtpPurpose::Register))->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'broker.applied')->sole()->subject_label)->toBe($broker->code);

    // The PAN itself is stored nowhere in the clear.
    expect(json_encode(DB::table('brokers')->first()))->not->toContain(TEST_PAN);
});

it('the mobile is proved on /verify-otp: signed in, device trusted, and the portal is the landing page', function (): void {
    $result = app(ApplyAsBroker::class)->handle(brokerApplication(), '10.0.0.5');
    $code = (string) $this->sms->lastCodeFor('+919847012345', OtpPurpose::Register);

    $user = app(CompleteRegistration::class)->handle(PhoneNumber::fromE164('+919847012345'), $result->user?->id, $code, requestWithSession());

    expect($user->hasVerifiedPhone())->toBeTrue()
        ->and(auth('web')->id())->toBe($user->id)
        ->and(TrustedDevice::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(app(App\Support\Navigation\MemberLanding::class)->url($user))->toBe(route('broker.dashboard'));
});

it('R-M13-22 / R-M01-4: a mobile already on Oppam gets nothing created and the owner an "account exists" SMS', function (): void {
    $member = memberWithPhone('+919847012345');

    $result = app(ApplyAsBroker::class)->handle(brokerApplication(), '10.0.0.5');

    expect($result->user)->toBeNull()
        ->and(Broker::query()->count())->toBe(0)
        ->and(User::query()->findOrFail($member->id)->role)->toBe(UserRole::Member)
        ->and($this->sms->accountExistsNotices)->toBe(['+919847012345'])
        ->and($this->sms->lastCodeFor('+919847012345', OtpPurpose::Register))->toBeNull();
});

it('an unfinished earlier application (mobile never proved) is released and replaced', function (): void {
    app(ApplyAsBroker::class)->handle(brokerApplication(['pan' => 'PQRST6789K']), '10.0.0.5');
    $this->travel(2)->minutes();

    $result = app(ApplyAsBroker::class)->handle(brokerApplication(), '10.0.0.6');

    expect($result->user)->not->toBeNull()
        ->and(Broker::query()->count())->toBe(1)
        ->and(Broker::query()->sole()->pan_last4)->toBe('234F');
});

it('one PAN = one bureau: a second application with the same PAN is refused', function (): void {
    Broker::factory()->create(['pan_hash' => PanNumber::hash(TEST_PAN), 'pan_last4' => '234F']);

    expect(fn () => app(ApplyAsBroker::class)->handle(brokerApplication(), '10.0.0.5'))->toThrow(ValidationException::class)
        ->and(User::query()->where('role', UserRole::Broker)->count())->toBe(0);
});

it('P7.1 review: an application whose mobile was never proved holds its PAN only for the hold window, then a real bureau gets it', function (): void {
    app(ApplyAsBroker::class)->handle(brokerApplication(['phone' => PhoneNumber::fromParts('91', '9847099999')]), '10.0.0.5');   // the squatter

    $this->travel(5)->minutes();
    expect(fn () => app(ApplyAsBroker::class)->handle(brokerApplication(), '10.0.0.6'))
        ->toThrow(ValidationException::class, 'waiting for its mobile number to be confirmed');

    $this->travel((int) config('oppam.broker.unverified_pan_hold_minutes'))->minutes();
    $result = app(ApplyAsBroker::class)->handle(brokerApplication(), '10.0.0.6');

    $broker = Broker::query()->sole();
    expect($broker->phone)->toBe('+919847012345')
        ->and($result->user?->phone)->toBe('+919847012345')
        ->and(User::query()->where('phone', '+919847099999')->exists())->toBeFalse();
});

it('P7.1 review: a bureau whose Owner proved the mobile keeps its PAN, however old the application', function (): void {
    $owner = applicantOwner();                                         // phone verified, APPLIED
    $owner->brokerStaff->broker->forceFill(['pan_hash' => PanNumber::hash(TEST_PAN), 'pan_last4' => '234F'])->save();
    $this->travel(2)->days();

    expect(fn () => app(ApplyAsBroker::class)->handle(brokerApplication(), '10.0.0.6'))
        ->toThrow(ValidationException::class, 'already linked to a bureau')
        ->and(Broker::query()->sole()->id)->toBe($owner->brokerStaff->broker_id);
});

it('caps applications per IP (rule 10)', function (): void {
    foreach (range(1, 5) as $i) {
        app(ApplyAsBroker::class)->handle(brokerApplication(['phone' => PhoneNumber::fromParts('91', '98470123'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)), 'pan' => 'ABCDE'.(1000 + $i).'F']), '10.9.9.9');
    }

    expect(fn () => app(ApplyAsBroker::class)->handle(brokerApplication(['pan' => 'ZZZZZ9999Z']), '10.9.9.9'))->toThrow(RegistrationFailed::class);
});

it('B.3: with broker.self_apply off the page and the Action don’t exist (404)', function (): void {
    App\Models\FeatureFlag::query()->where('key', Flag::BrokerSelfApply->value)->update(['is_enabled' => false]);
    app(App\Services\Settings\FeatureFlags::class)->flush();
    app()->forgetInstance(App\Services\Settings\FeatureFlags::class);

    $this->get(brokerUrl('/apply'))->assertNotFound();
    expect(fn () => app(ApplyAsBroker::class)->handle(brokerApplication(), '10.0.0.5'))->toThrow(Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
});

it('/broker/apply: renders for guests, validates server-side, and goes on to /verify-otp', function (): void {
    $this->get(brokerUrl('/apply'))->assertOk()->assertSee('Apply as a marriage bureau');

    Livewire::test(Apply::class)
        ->set('form.name', 'R2')
        ->set('form.pan', 'ABC')
        ->set('form.districtId', '999999')
        ->call('apply')
        ->assertHasErrors(['form.name', 'form.pan', 'form.mobile', 'form.districtId', 'form.password', 'form.terms']);
    expect(Broker::query()->count())->toBe(0);

    Livewire::test(Apply::class)
        ->set('form.name', 'Rajan Pillai')
        ->set('form.mobile', '9847012345')
        ->set('form.districtId', (string) District::query()->value('id'))
        ->set('form.pan', 'abcde 1234f')
        ->set('form.password', TEST_MEMBER_PASSWORD)
        ->set('form.terms', true)
        ->call('apply')
        ->assertHasNoErrors()
        ->assertRedirect(route('register.verify'))
        ->assertSet('form.password', '');

    expect(Broker::query()->sole()->pan_last4)->toBe('234F');
});

it('signed-in users are sent away from the application (guest only)', function (): void {
    $this->actingAs(groom(), 'web')->get(brokerUrl('/apply'))->assertRedirect();
});
