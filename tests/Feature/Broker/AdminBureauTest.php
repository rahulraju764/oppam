<?php

declare(strict_types=1);

use App\Actions\Admin\Brokers\CreateBureau;
use App\Actions\Admin\Brokers\UpdateBureauTerms;
use App\Actions\Broker\ApplyAsBroker;
use App\Data\Broker\BrokerApplicationData;
use App\Data\Broker\BureauTermsData;
use App\Data\Broker\NewBureauData;
use App\Enums\BrokerKycStatus;
use App\Enums\BrokerStatus;
use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Enums\Flag;
use App\Exceptions\Admin\BrokerStateConflict;
use App\Livewire\Broker\Apply;
use App\Models\AuditLog;
use App\Models\Broker;
use App\Models\BrokerStaff;
use App\Models\Masters\District;
use App\ValueObjects\PhoneNumber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
| P7.8 — A07 "create broker" and Overview edits (PRD §11A B.12, B.17, R-A07-1, R-A07-2, R-A07-11;
| decisions 2026-10-29 "the Owner applies normally"): the code is generated, the bureau waits for
| its Owner, whose application with the same mobile is linked to it (KYC as usual, even with
| broker.self_apply off through ?bureau=<code>); terms are range-checked, permission-split and audited.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    $this->admin = adminWithRole('super_admin');
    $this->sms = fakeSms();
});

function newBureau(string $mobile = '9847099001'): NewBureauData
{
    return new NewBureauData('Lakshmi Nair', 'Thrissur Kalyanam', PhoneNumber::fromParts('91', $mobile), null, (int) District::query()->value('id'));
}

function ownerApplication(string $mobile = '9847099001', string $pan = TEST_PAN): BrokerApplicationData
{
    return new BrokerApplicationData('Lakshmi Nair', null, PhoneNumber::fromParts('91', $mobile), 'lakshmi@example.com',
        (int) District::query()->value('id'), $pan, TEST_MEMBER_PASSWORD);
}

it('R-A07-1: an admin creates a bureau — generated code, APPLIED, no KYC, no Owner yet; audited', function (): void {
    $broker = app(CreateBureau::class)->handle($this->admin, newBureau());

    expect($broker->code)->toMatch('/^BRK\d{4,}$/')
        ->and($broker->status)->toBe(BrokerStatus::Applied)
        ->and($broker->kyc_status)->toBe(BrokerKycStatus::NotSubmitted)
        ->and($broker->created_by_admin_id)->toBe($this->admin->id)
        ->and(BrokerStaff::query()->where('broker_id', $broker->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'brokers.created')->sole()->after['phone'])->not->toContain('9847099001');
});

it('a mobile that is already an Oppam login or another open bureau is refused', function (string $case): void {
    match ($case) {
        'member' => memberWithPhone('+919847099001'),
        'bureau' => Broker::factory()->create(['phone' => '+919847099001']),
    };

    expect(fn () => app(CreateBureau::class)->handle($this->admin, newBureau()))->toThrow(BrokerStateConflict::class);
})->with(['member', 'bureau']);

it('needs brokers.edit (finance can’t create bureaus)', function (): void {
    expect(fn () => app(CreateBureau::class)->handle(adminWithRole('finance'), newBureau()))->toThrow(AuthorizationException::class)
        ->and(Broker::query()->count())->toBe(0);
});

it('R-A07-2: the Owner applies with the same mobile — linked to the admin-created bureau (code and terms kept), KYC still to do', function (): void {
    flagOn(Flag::BrokerSelfApply);
    $broker = app(CreateBureau::class)->handle($this->admin, newBureau());
    $broker->forceFill(['commission_bps' => 1500])->save();

    $result = app(ApplyAsBroker::class)->handle(ownerApplication(), '10.0.0.9');

    $owner = BrokerStaff::query()->sole();
    expect(Broker::query()->count())->toBe(1)
        ->and($owner->broker_id)->toBe($broker->id)
        ->and($owner->role)->toBe(BureauRole::Owner)
        ->and($owner->status)->toBe(BureauStaffStatus::Invited)
        ->and($owner->user_id)->toBe($result->user?->id)
        ->and($broker->refresh()->code)->toBe($broker->code)
        ->and($broker->commission_bps)->toBe(1500)
        ->and($broker->status)->toBe(BrokerStatus::Applied)
        ->and($broker->kyc_status)->toBe(BrokerKycStatus::NotSubmitted)
        ->and($broker->pan_last4)->toBe('234F')
        ->and(AuditLog::query()->where('action', 'broker.applied')->sole()->after['admin_created'])->toBeTrue();
});

it('with broker.self_apply off: /broker/apply exists only with a waiting bureau’s code, and only its mobile is accepted', function (): void {
    $broker = app(CreateBureau::class)->handle($this->admin, newBureau());

    $this->get(memberUrl('/broker/apply'))->assertNotFound();
    $this->get(memberUrl('/broker/apply?bureau=BRK999999'))->assertNotFound();
    $this->get(memberUrl('/broker/apply?bureau='.strtolower($broker->code)))->assertOk()->assertSee($broker->code);

    expect(fn () => app(ApplyAsBroker::class)->handle(ownerApplication('9847099002'), '10.0.0.9', $broker->code))
        ->toThrow(ValidationException::class);
    expect(fn () => app(ApplyAsBroker::class)->handle(ownerApplication(), '10.0.0.9'))->toThrow(HttpException::class);

    app(ApplyAsBroker::class)->handle(ownerApplication(), '10.0.0.9', $broker->code);
    expect(BrokerStaff::query()->sole()->broker_id)->toBe($broker->id);

    // Once linked the code no longer opens the page.
    $this->get(memberUrl('/broker/apply?bureau='.$broker->code))->assertNotFound();
});

it('the ?bureau code is locked on the page — a client can’t swap it', function (): void {
    $broker = app(CreateBureau::class)->handle($this->admin, newBureau());

    Livewire::withQueryParams(['bureau' => $broker->code])->test(Apply::class)
        ->assertSet('bureau', $broker->code)
        ->set('bureau', 'BRK1');
})->throws(CannotUpdateLockedPropertyException::class);

it('an unfinished application to an admin-created bureau is released without deleting the bureau', function (): void {
    flagOn(Flag::BrokerSelfApply);
    $broker = app(CreateBureau::class)->handle($this->admin, newBureau());
    app(ApplyAsBroker::class)->handle(ownerApplication(), '10.0.0.9');           // never proves the mobile

    app(ApplyAsBroker::class)->handle(ownerApplication(), '10.0.0.10');          // applies again

    expect(Broker::query()->count())->toBe(1)
        ->and(BrokerStaff::query()->sole()->broker_id)->toBe($broker->id);
});

it('an unproved application never rewrites the details A07 entered (name, bureau name, district)', function (): void {
    flagOn(Flag::BrokerSelfApply);
    $broker = app(CreateBureau::class)->handle($this->admin, newBureau());
    $district = (int) District::query()->where('id', '!=', $broker->district_id)->value('id');

    app(ApplyAsBroker::class)->handle(new BrokerApplicationData('Someone Else', 'Fake Bureau', PhoneNumber::fromParts('91', '9847099001'),
        null, $district, TEST_PAN, TEST_MEMBER_PASSWORD), '10.0.0.9');

    $broker->refresh();
    expect($broker->name)->toBe('Lakshmi Nair')
        ->and($broker->bureau_name)->toBe('Thrissur Kalyanam')
        ->and($broker->district_id)->not->toBe($district);
});

it('R-A07-11: terms are audited old → new with the reason; lowering seats deactivates nobody', function (): void {
    $owner = bureauLogin();
    $broker = staffOf($owner)->broker;
    bureauLogin(BureauRole::Telecaller, broker: $broker);
    bureauLogin(BureauRole::DataEntry, broker: $broker);

    $changed = app(UpdateBureauTerms::class)->handle($this->admin, $broker, new BureauTermsData(1200, 150, 1, 500), 'Agreed on the phone');

    $audit = AuditLog::query()->where('action', 'brokers.terms_updated')->sole();
    expect($changed)->toBe(['commission_bps' => 1200, 'interest_allowance_monthly' => 150, 'staff_seat_limit' => 1, 'import_daily_cap' => 500])
        ->and($audit->before)->toBe(['commission_bps' => 1000, 'interest_allowance_monthly' => 100, 'staff_seat_limit' => 5, 'import_daily_cap' => 1000])
        ->and($audit->reason)->toBe('Agreed on the phone')
        ->and(BrokerStaff::query()->where('broker_id', $broker->id)->where('status', BureauStaffStatus::Active)->count())->toBe(3);
});

it('B.17: commission 0–10000 bps and the other limits are range-checked; nothing changed is refused', function (BureauTermsData $terms, string $field): void {
    $broker = Broker::factory()->create();

    try {
        app(UpdateBureauTerms::class)->handle($this->admin, $broker, $terms, 'A good reason');
        $this->fail('expected a validation error');
    } catch (ValidationException $invalid) {
        expect($invalid->errors())->toHaveKey($field);
    }
    expect(AuditLog::query()->where('action', 'brokers.terms_updated')->exists())->toBeFalse();
})->with([
    'commission above 100 %' => [new BureauTermsData(10_001, 100, 5, 1000), 'commission_bps'],
    'negative commission' => [new BureauTermsData(-1, 100, 5, 1000), 'commission_bps'],
    'seats above 100' => [new BureauTermsData(1000, 100, 101, 1000), 'staff_seat_limit'],
    'import cap above 20,000' => [new BureauTermsData(1000, 100, 5, 20_001), 'import_daily_cap'],
    'nothing changed' => [new BureauTermsData(1000, 100, 5, 1000), 'reason'],
]);

it('the permission follows the field: commission needs brokers.commission_bps, limits need brokers.edit', function (): void {
    $broker = Broker::factory()->create();
    $finance = adminWithRole('finance');                 // commission_bps, not edit
    $support = adminWithRole('support');                 // neither

    app(UpdateBureauTerms::class)->handle($finance, $broker, new BureauTermsData(800, 100, 5, 1000), 'New rate agreed');
    expect($broker->refresh()->commission_bps)->toBe(800);

    expect(fn () => app(UpdateBureauTerms::class)->handle($finance, $broker, new BureauTermsData(800, 100, 9, 1000), 'More seats'))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateBureauTerms::class)->handle($support, $broker, new BureauTermsData(900, 100, 5, 1000), 'A good reason'))
        ->toThrow(AuthorizationException::class)
        ->and($broker->refresh()->staff_seat_limit)->toBe(5);
});
