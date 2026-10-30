<?php

declare(strict_types=1);

use App\Actions\Admin\Brokers\DeactivateBroker;
use App\Actions\Admin\Brokers\ReactivateBroker;
use App\Actions\Admin\Brokers\SuspendBroker;
use App\Domain\Broker\BureauGate;
use App\Enums\BrokerStatus;
use App\Enums\BureauPermission;
use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Events\Account\ForceLogout;
use App\Events\Broker\BrokerSuspended;
use App\Exceptions\Admin\BrokerStateConflict;
use App\Models\AuditLog;
use App\Models\Broker;
use App\Notifications\Broker\BureauStatusChanged;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
| P7.8 Done-when: "deactivating a bureau blocks Owner + staff instantly" (PRD §11A R-A07-8, R-M13-8,
| R-M13-21, B.15 BrokerSuspended, B.21 "deactivating a bureau blocks Owner and staff at once and logs
| staff out live"). Suspend / deactivate end every session of the bureau's logins, broadcast
| ForceLogout to each and BrokerSuspended on the bureau's channels, and every later request is
| refused; create / import / export are refused for everyone; reactivation restores the same team.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    Notification::fake();
    $this->admin = adminWithRole('super_admin');
    $this->owner = bureauLogin(BureauRole::Owner);
    $this->broker = staffOf($this->owner)->broker;
    $this->manager = bureauLogin(BureauRole::Manager, broker: $this->broker);
    $this->telecaller = bureauLogin(BureauRole::Telecaller, broker: $this->broker);
});

it('★ R-A07-8 / B.21: deactivating blocks the Owner and all staff at once — sessions ended, ForceLogout to each, BrokerSuspended live', function (string $how): void {
    Event::fake([ForceLogout::class, BrokerSuspended::class]);
    $epochs = collect([$this->owner, $this->manager, $this->telecaller])->mapWithKeys(fn ($u) => [$u->id => $u->session_epoch]);

    $how === 'deactivate'
        ? app(DeactivateBroker::class)->handle($this->admin, $this->broker, 'Fraudulent referrals confirmed')
        : app(SuspendBroker::class)->handle($this->admin, $this->broker, 'Referral spike under review');

    $status = $how === 'deactivate' ? BrokerStatus::Deactivated : BrokerStatus::Suspended;
    expect($this->broker->refresh()->status)->toBe($status);
    foreach ([$this->owner, $this->manager, $this->telecaller] as $login) {
        expect($login->refresh()->session_epoch)->toBe($epochs[$login->id] + 1);
        Event::assertDispatched(ForceLogout::class, fn (ForceLogout $e): bool => $e->userId === $login->id);
    }
    Event::assertDispatched(BrokerSuspended::class, fn (BrokerSuspended $e): bool => $e->broadcastWith() === ['status' => $status->value]
        && collect($e->broadcastOn())->pluck('name')->all() === ['private-broker-owner.'.$this->broker->id, 'private-broker.'.$this->broker->id]
        && $e->broadcastAs() === 'bureau.suspended');
})->with(['deactivate', 'suspend']);

it('★ R-A07-8: the next request of the Owner and of a staff member is signed out', function (BureauRole $role): void {
    $login = $role === BureauRole::Owner ? $this->owner : $this->telecaller;
    actingAsBureau($login);
    $this->get(brokerUrl())->assertOk();

    app(DeactivateBroker::class)->handle($this->admin, $this->broker, 'Bureau closed by request');

    $this->get(brokerUrl())->assertRedirect(route('login'));
    expect(auth('web')->check())->toBeFalse();
})->with([BureauRole::Owner, BureauRole::Telecaller]);

it('★ R-A07-8 / R-M13-8: create, import and export are refused for every login of a suspended bureau', function (): void {
    $gate = app(BureauGate::class);
    expect($gate->allows($this->owner, BureauPermission::ProfilesCreate))->toBeTrue();

    app(SuspendBroker::class)->handle($this->admin, $this->broker, 'Referral spike under review');
    $gate->forget();

    foreach ([$this->owner, $this->manager] as $login) {
        foreach ([BureauPermission::ProfilesCreate, BureauPermission::ProfilesImport, BureauPermission::ProfilesExport] as $permission) {
            expect($gate->allows($login, $permission))->toBeFalse();
        }
    }
});

it('reactivation restores the same team (staff rows untouched) and the logins can sign in again', function (): void {
    app(SuspendBroker::class)->handle($this->admin, $this->broker, 'Referral spike under review');
    expect(staffOf($this->telecaller)->status)->toBe(BureauStaffStatus::Active);

    app(ReactivateBroker::class)->handle($this->admin, $this->broker->refresh(), 'Review closed, all fine');

    expect($this->broker->refresh()->status)->toBe(BrokerStatus::Active)
        ->and($this->broker->status_reason)->toBeNull();
    actingAsBureau($this->telecaller->refresh());
    $this->get(brokerUrl())->assertOk();
});

it('the bureau’s working logins are told (never the reason); a staff login the Owner switched off is not', function (): void {
    $off = bureauLogin(BureauRole::DataEntry, broker: $this->broker, status: BureauStaffStatus::Deactivated);

    app(SuspendBroker::class)->handle($this->admin, $this->broker, 'Secret internal reason');

    Notification::assertSentTo([$this->owner, $this->manager, $this->telecaller], BureauStatusChanged::class,
        fn (BureauStatusChanged $n): bool => $n->status === BrokerStatus::Suspended
            && ! str_contains($n->content($this->owner)->body, 'Secret'));
    Notification::assertNotSentTo($off, BureauStatusChanged::class);
});

it('audited with the reason and the number of logins signed out', function (): void {
    app(DeactivateBroker::class)->handle($this->admin, $this->broker, 'Bureau closed by request');

    $audit = AuditLog::query()->where('action', 'brokers.deactivated')->sole();
    expect($audit->reason)->toBe('Bureau closed by request')
        ->and($audit->before)->toBe(['status' => 'ACTIVE'])
        ->and($audit->after['logins_signed_out'])->toBe(3)
        ->and($audit->subject_label)->toBe($this->broker->code);
});

it('state rules: suspend only ACTIVE; reactivate only SUSPENDED; deactivate ACTIVE or SUSPENDED; deactivated is final', function (): void {
    $applied = Broker::factory()->applied()->create();

    expect(fn () => app(SuspendBroker::class)->handle($this->admin, $applied, 'A good reason'))->toThrow(BrokerStateConflict::class)
        ->and(fn () => app(ReactivateBroker::class)->handle($this->admin, $this->broker, 'A good reason'))->toThrow(BrokerStateConflict::class)
        ->and(fn () => app(DeactivateBroker::class)->handle($this->admin, $applied, 'A good reason'))->toThrow(BrokerStateConflict::class);

    app(SuspendBroker::class)->handle($this->admin, $this->broker, 'A good reason');
    app(DeactivateBroker::class)->handle($this->admin, $this->broker->refresh(), 'A good reason');
    expect(fn () => app(ReactivateBroker::class)->handle($this->admin, $this->broker->refresh(), 'A good reason'))->toThrow(BrokerStateConflict::class);
});

it('needs brokers.edit and a 5–300 character reason; nothing changes otherwise', function (): void {
    expect(fn () => app(SuspendBroker::class)->handle(adminWithRole('finance'), $this->broker, 'A good reason'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SuspendBroker::class)->handle($this->admin, $this->broker, 'no'))->toThrow(ValidationException::class)
        ->and(fn () => app(DeactivateBroker::class)->handle($this->admin, $this->broker, str_repeat('x', 301)))->toThrow(ValidationException::class)
        ->and($this->broker->refresh()->status)->toBe(BrokerStatus::Active)
        ->and(AuditLog::query()->where('action', 'like', 'brokers.%')->count())->toBe(0);
});

it('R-M13-8: a suspended bureau earns nothing new and is left out of payout runs', function (): void {
    earnedCommission($this->broker, 60_000);
    earnedCommission(Broker::factory()->create(), 60_000);
    app(SuspendBroker::class)->handle($this->admin, $this->broker, 'Referral spike under review');

    $run = createRun($this->admin);

    expect($run->items()->where('broker_id', $this->broker->id)->exists())->toBeFalse();
});
