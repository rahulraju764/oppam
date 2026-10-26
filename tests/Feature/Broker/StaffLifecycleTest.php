<?php

declare(strict_types=1);

use App\Actions\Broker\Staff\AcceptStaffInvite;
use App\Actions\Broker\Staff\CancelInvitation;
use App\Actions\Broker\Staff\DeactivateStaff;
use App\Actions\Broker\Staff\InviteStaff;
use App\Actions\Broker\Staff\UpdateStaffPermissions;
use App\Data\Broker\StaffInviteData;
use App\Domain\Broker\BureauGate;
use App\Domain\Broker\StaffSeats;
use App\Enums\BureauPermission;
use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Enums\Flag;
use App\Enums\OtpPurpose;
use App\Enums\ProfileScope;
use App\Enums\UserRole;
use App\Events\Account\ForceLogout;
use App\Exceptions\Auth\OtpInvalid;
use App\Exceptions\Broker\StaffRefused;
use App\Models\AuditLog;
use App\Models\BrokerStaff;
use App\Models\BrokerStaffInvitation;
use App\Models\Profile;
use App\Models\TrustedDevice;
use App\Models\User;
use Database\Seeders\PlansSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
| StaffLifecycleTest (PRD §11A B.8, B.15, B.21 Staff, B.22; R-M13-18..25) — P7.5: invite (seat limit,
| 72 h hashed single-use link, one mobile = one login), accept (password + OTP), switch-offs within
| the role ceiling, deactivate (ForceLogout, sessions ended, clients unassigned, seat freed), and
| audit rows with the individual user (R-M13-23).
*/

const STAFF_PHONE = '+919876500011';

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    flagOn(Flag::BrokerStaff);
    $this->sms = fakeSms();
});

function invite(User $owner, string $phone = '9876500011', string $role = 'TELECALLER', string $scope = 'ASSIGNED', string $name = 'Ravi Kumar'): BrokerStaffInvitation
{
    return app(InviteStaff::class)->handle($owner, new StaffInviteData($name, $phone, $role, $scope));
}

/** The plain token from the last invitation SMS to $e164. */
function inviteToken(object $test, string $e164 = STAFF_PHONE): string
{
    $notice = collect($test->sms->notices)->last(fn (array $n): bool => $n['to'] === $e164 && $n['key'] === 'staff_invite');
    preg_match('#/broker/staff/invite/([A-Za-z0-9]{64})#', (string) ($notice['variables'][1] ?? ''), $match);

    return $match[1] ?? '';
}

/** Accept the invitation behind $token through both steps; returns the new login. */
function acceptInvite(object $test, string $token, string $e164 = STAFF_PHONE): User
{
    $accept = app(AcceptStaffInvite::class);
    $hash = $accept->start($token, 'kerala2026', 'kerala2026', '10.1.1.1');

    return $accept->verify($token, (string) $test->sms->lastCodeFor($e164, OtpPurpose::StaffInvite), $hash, requestWithSession());
}

// ---- Invite -----------------------------------------------------------------------------------------

it('B.8: the Owner invites — a 72 h link texted to the mobile, only its hash stored, audited with the Owner', function (): void {
    $this->freezeTime();
    $owner = bureauLogin();

    $invitation = invite($owner);
    $token = inviteToken($this);

    expect($token)->toHaveLength(64)
        ->and($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and(BrokerStaffInvitation::query()->where('token_hash', $token)->exists())->toBeFalse()
        ->and($invitation->expires_at->toDateTimeString())->toBe(now()->addHours(72)->toDateTimeString())
        ->and($invitation->role)->toBe(BureauRole::Telecaller)
        ->and($invitation->profile_scope)->toBe(ProfileScope::Assigned)
        ->and($invitation->phone)->toBe(STAFF_PHONE)
        ->and($this->sms->notices[0]['variables'][0])->toBe(staffOf($owner)->broker->displayName());

    $audit = AuditLog::query()->where('action', 'broker.staff_invited')->sole();
    expect($audit->actor_id)->toBe($owner->id)->and(json_encode($audit->after))->not->toContain('76500011');
});

it('★ R-M13-25: invites stop at the seat limit; open invitations hold a seat; cancelled / expired ones and deactivated staff free it', function (): void {
    $owner = bureauLogin();
    $broker = staffOf($owner)->broker;
    $broker->forceFill(['staff_seat_limit' => 2])->save();
    $active = bureauLogin(BureauRole::DataEntry, broker: $broker);   // 1 seat (the Owner uses none)

    $first = invite($owner, '9876500021');                            // 2 seats
    expect(fn () => invite($owner, '9876500022'))->toThrow(StaffRefused::class, 'All 2 staff seats are in use');

    app(CancelInvitation::class)->handle($owner, $first->id);
    invite($owner, '9876500022');
    expect(fn () => invite($owner, '9876500023'))->toThrow(StaffRefused::class);

    $this->travel(73)->hours();                                       // the open invite expired
    invite($owner, '9876500023');

    app(DeactivateStaff::class)->handle($owner, staffOf($active)->code);
    invite($owner, '9876500024');

    expect(app(StaffSeats::class)->used($broker->refresh()))->toBe(2);
});

it('R-A07-11: lowering the limit below the staff count deactivates nobody; new invites just wait', function (): void {
    $owner = bureauLogin();
    $broker = staffOf($owner)->broker;
    bureauLogin(BureauRole::Manager, broker: $broker);
    bureauLogin(BureauRole::Telecaller, broker: $broker);
    $broker->forceFill(['staff_seat_limit' => 1])->save();

    expect(BrokerStaff::query()->where('broker_id', $broker->id)->where('status', BureauStaffStatus::Active)->count())->toBe(3)
        ->and(fn () => invite($owner))->toThrow(StaffRefused::class);
});

it('a new invitation to the same number replaces the open one (one link, one seat)', function (): void {
    $owner = bureauLogin();
    invite($owner);
    $old = inviteToken($this);
    invite($owner, role: 'MANAGER', scope: 'ALL');
    $new = inviteToken($this);

    expect(app(AcceptStaffInvite::class)->find($old))->toBeNull()
        ->and(app(AcceptStaffInvite::class)->find($new)?->role)->toBe(BureauRole::Manager)
        ->and(app(StaffSeats::class)->openInvitations(staffOf($owner)->broker))->toBe(1);
});

it('★ R-M13-22: a number already on any Oppam login is refused — generic message — except a login this bureau deactivated', function (string $case): void {
    $owner = bureauLogin();
    $broker = staffOf($owner)->broker;

    match ($case) {
        'a member' => memberWithPhone(STAFF_PHONE),
        'another bureau’s staff' => bureauLogin(BureauRole::Telecaller)->forceFill(['phone' => STAFF_PHONE])->save(),
        'an Owner' => $owner->forceFill(['phone' => STAFF_PHONE])->save(),
        'our active staff' => bureauLogin(BureauRole::Telecaller, broker: $broker)->forceFill(['phone' => STAFF_PHONE])->save(),
        'a deleted member' => memberWithPhone(STAFF_PHONE)->delete(),
    };

    try {
        invite($owner);
        $this->fail('expected a refusal');
    } catch (StaffRefused $e) {
        expect($e->getMessage())->toBe('This number can’t be invited. Ask for another mobile number.');
    }
})->with(['a member', 'another bureau’s staff', 'an Owner', 'our active staff', 'a deleted member']);

it('B.2 / R-M13-24: only the Owner invites (staff.manage); the flag and a verified bureau are required', function (): void {
    $owner = bureauLogin();
    $broker = staffOf($owner)->broker;

    foreach ([BureauRole::Manager, BureauRole::DataEntry, BureauRole::Telecaller] as $role) {
        expect(fn () => invite(bureauLogin($role, broker: $broker)))->toThrow(HttpException::class);
    }

    expect(fn () => invite(bureauLogin(BureauRole::Owner, 'applied')))->toThrow(StaffRefused::class, 'KYC');                   // bureau not verified yet
    expect(fn () => invite(bureauLogin(BureauRole::Owner, 'applied', status: BureauStaffStatus::Invited)))->toThrow(HttpException::class);   // applicant Owner: pre-KYC pages only

    $broker->forceFill(['kyc_status' => App\Enums\BrokerKycStatus::Rejected])->save();
    expect(fn () => invite($owner))->toThrow(StaffRefused::class, 'KYC');

    App\Models\FeatureFlag::query()->where('key', Flag::BrokerStaff->value)->update(['is_enabled' => false]);
    app(App\Services\Settings\FeatureFlags::class)->flush();
    expect(fn () => invite(bureauLogin()))->toThrow(StaffRefused::class, 'coming soon');

    expect(BrokerStaffInvitation::query()->count())->toBe(0);
});

it('validates the form server-side: a second Owner can’t be invited; bad mobile / scope / name → 422', function (array $input, string $field): void {
    $owner = bureauLogin();

    try {
        app(InviteStaff::class)->handle($owner, new StaffInviteData(...array_merge(['name' => 'Ravi', 'phone' => '9876500011', 'role' => 'MANAGER', 'scope' => 'ALL'], $input)));
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }

    expect(BrokerStaffInvitation::query()->count())->toBe(0);
})->with([
    'owner role' => [['role' => 'OWNER'], 'role'],
    'unknown role' => [['role' => 'ADMIN'], 'role'],
    'bad mobile' => [['phone' => '12345'], 'phone'],
    'unknown scope' => [['scope' => 'EVERYONE'], 'scope'],
    'no name' => [['name' => ''], 'name'],
]);

// ---- Accept -----------------------------------------------------------------------------------------

it('★ B.8: password + OTP make the invitee an ACTIVE staff member with the invitation’s role and scope, signed in', function (): void {
    $owner = bureauLogin();
    invite($owner, role: 'MANAGER', scope: 'ALL', name: 'Meera Nair');

    $login = acceptInvite($this, inviteToken($this));

    $staff = staffOf($login);
    expect($login->role)->toBe(UserRole::Broker)
        ->and($login->phone)->toBe(STAFF_PHONE)
        ->and($login->hasVerifiedPhone())->toBeTrue()
        ->and(Illuminate\Support\Facades\Hash::check('kerala2026', $login->password))->toBeTrue()
        ->and($staff->broker_id)->toBe(staffOf($owner)->broker_id)
        ->and($staff->role)->toBe(BureauRole::Manager)
        ->and($staff->profile_scope)->toBe(ProfileScope::All)
        ->and($staff->status)->toBe(BureauStaffStatus::Active)
        ->and($staff->name)->toBe('Meera Nair')
        ->and($staff->code)->toStartWith('STF')
        ->and($staff->invited_by_user_id)->toBe($owner->id)
        ->and(auth('web')->id())->toBe($login->id)
        ->and(BrokerStaffInvitation::query()->sole()->accepted_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'broker.staff_joined')->where('actor_id', $login->id)->exists())->toBeTrue();
});

it('the link works once, and not after 72 h, a cancel or a suspended bureau — one generic answer', function (string $case): void {
    $owner = bureauLogin();
    $invitation = invite($owner);
    $token = inviteToken($this);

    match ($case) {
        'used' => acceptInvite($this, $token),
        'expired' => $this->travel(73)->hours(),
        'cancelled' => app(CancelInvitation::class)->handle($owner, $invitation->id),
        'bureau suspended' => staffOf($owner)->broker->forceFill(['status' => App\Enums\BrokerStatus::Suspended])->save(),
        'tampered token' => $token = substr($token, 0, 63).($token[63] === 'a' ? 'b' : 'a'),
    };
    auth('web')->logout();

    expect(app(AcceptStaffInvite::class)->find($token))->toBeNull()
        ->and(fn () => app(AcceptStaffInvite::class)->start($token, 'kerala2026', 'kerala2026', '10.1.1.1'))
        ->toThrow(StaffRefused::class, 'no longer valid');
})->with(['used', 'expired', 'cancelled', 'bureau suspended', 'tampered token']);

it('a wrong code activates nothing; the login stays unverified and staff-less', function (): void {
    invite(bureauLogin());
    $token = inviteToken($this);
    $hash = app(AcceptStaffInvite::class)->start($token, 'kerala2026', 'kerala2026', '10.1.1.1');

    expect(fn () => app(AcceptStaffInvite::class)->verify($token, '000000', $hash, requestWithSession()))->toThrow(OtpInvalid::class);

    $user = User::query()->where('phone', STAFF_PHONE)->sole();
    expect($user->hasVerifiedPhone())->toBeFalse()
        ->and(BrokerStaff::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('the password follows the member rules and must be confirmed', function (): void {
    invite(bureauLogin());

    expect(fn () => app(AcceptStaffInvite::class)->start(inviteToken($this), 'short', 'short', '10.1.1.1'))->toThrow(ValidationException::class)
        ->and(fn () => app(AcceptStaffInvite::class)->start(inviteToken($this), 'kerala2026', 'kerala2027', '10.1.1.1'))->toThrow(ValidationException::class);
});

it('R-M13-25: a seat taken meanwhile (limit lowered) refuses the acceptance', function (): void {
    $owner = bureauLogin();
    invite($owner);
    $token = inviteToken($this);
    $hash = app(AcceptStaffInvite::class)->start($token, 'kerala2026', 'kerala2026', '10.1.1.1');
    $broker = staffOf($owner)->broker;
    bureauLogin(BureauRole::DataEntry, broker: $broker);
    $broker->forceFill(['staff_seat_limit' => 1])->save();

    expect(fn () => app(AcceptStaffInvite::class)->verify($token, (string) $this->sms->lastCodeFor(STAFF_PHONE, OtpPurpose::StaffInvite), $hash, requestWithSession()))
        ->toThrow(StaffRefused::class);
});

it('re-inviting a login this bureau deactivated reactivates it with the new role on acceptance', function (): void {
    $owner = bureauLogin();
    invite($owner);
    $login = acceptInvite($this, inviteToken($this));
    $code = staffOf($login)->code;
    app(DeactivateStaff::class)->handle($owner, $code);
    auth('web')->logout();
    $this->travel(31)->seconds();   // the OTP gap between two codes to one number

    invite($owner, role: 'DATA_ENTRY', scope: 'ALL');
    $again = acceptInvite($this, inviteToken($this));

    expect($again->id)->toBe($login->id)
        ->and(staffOf($again)->code)->toBe($code)
        ->and(staffOf($again)->status)->toBe(BureauStaffStatus::Active)
        ->and(staffOf($again)->role)->toBe(BureauRole::DataEntry);
});

// ---- Permissions -------------------------------------------------------------------------------------

it('★ R-M13-19: switch-offs only within the role ceiling; applied on the very next request; audited', function (): void {
    $owner = bureauLogin();
    $manager = bureauLogin(BureauRole::Manager, broker: staffOf($owner)->broker);
    $code = staffOf($manager)->code;

    expect(app(BureauGate::class)->allows($manager, BureauPermission::ProfilesExport))->toBeTrue();

    app(UpdateStaffPermissions::class)->handle($owner, $code, ['profiles.export'], 'ASSIGNED');
    app(BureauGate::class)->forget();

    expect(app(BureauGate::class)->allows($manager->refresh(), BureauPermission::ProfilesExport))->toBeFalse()
        ->and(staffOf($manager)->profile_scope)->toBe(ProfileScope::Assigned)
        ->and(AuditLog::query()->where('action', 'broker.staff_permissions_changed')->sole()->before)->toBe(['disabled' => [], 'scope' => 'ALL']);

    foreach ([['staff.manage'], ['earnings.view'], ['bureau.dashboard.view'], ['not.a.key'], [42]] as $bad) {
        expect(fn () => app(UpdateStaffPermissions::class)->handle($owner, $code, $bad, 'ALL'))->toThrow(ValidationException::class);
    }
});

it('★ B.2 / R-M13-19: a Telecaller is always "assigned only" — ALL is refused at invite and on edit, and ignored if stored', function (): void {
    $owner = bureauLogin();
    $broker = staffOf($owner)->broker;

    try {
        invite($owner, role: 'TELECALLER', scope: 'ALL');
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors()['scope'][0])->toBe('Telecallers see only the clients assigned to them.');
    }

    $telecaller = bureauLogin(BureauRole::Telecaller, broker: $broker);
    expect(fn () => app(UpdateStaffPermissions::class)->handle($owner, staffOf($telecaller)->code, [], 'ALL'))->toThrow(ValidationException::class);

    // Even a row that says ALL (older data, a direct write) sees only its assigned clients.
    staffOf($telecaller)->forceFill(['profile_scope' => ProfileScope::All])->save();
    $mine = managedProfile($broker, [], staffOf($telecaller));
    $other = managedProfile($broker);
    $scope = app(App\Domain\Broker\ManagedProfileScope::class);

    expect($scope->query(staffOf($telecaller))->pluck('id')->all())->toBe([$mine->id])
        ->and($scope->covers(staffOf($telecaller), $other))->toBeFalse()
        ->and(fn () => app(App\Actions\Broker\ManagedProfiles\RespondToInterestForClient::class)->handle($telecaller, $other->code, 'OPM99999999', false))
        ->toThrow(ModelNotFoundException::class);
});

it('R-M13-24: the Owner’s own row can’t be restricted or deactivated; staff of another bureau are 404', function (): void {
    $owner = bureauLogin();
    $other = bureauLogin(BureauRole::Telecaller);

    expect(fn () => app(UpdateStaffPermissions::class)->handle($owner, staffOf($owner)->code, [], 'ALL'))->toThrow(StaffRefused::class)
        ->and(fn () => app(DeactivateStaff::class)->handle($owner, staffOf($owner)->code))->toThrow(StaffRefused::class)
        ->and(fn () => app(DeactivateStaff::class)->handle($owner, staffOf($other)->code))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(UpdateStaffPermissions::class)->handle($owner, staffOf($other)->code, [], 'ALL'))->toThrow(ModelNotFoundException::class);
    expect(staffOf($other)->status)->toBe(BureauStaffStatus::Active);
});

// ---- Deactivate -------------------------------------------------------------------------------------

it('★ B.8 / B.15: deactivating ends every session, broadcasts ForceLogout, unassigns their clients and frees the seat', function (): void {
    Event::fake([ForceLogout::class]);
    $owner = bureauLogin();
    $broker = staffOf($owner)->broker;
    $telecaller = bureauLogin(BureauRole::Telecaller, broker: $broker);
    $staff = staffOf($telecaller);
    $clients = [managedProfile($broker, [], $staff), managedProfile($broker, [], $staff)];
    TrustedDevice::factory()->create(['user_id' => $telecaller->id]);
    $epoch = $telecaller->session_epoch;

    expect(app(DeactivateStaff::class)->handle($owner, $staff->code))->toBe(2);

    expect(staffOf($telecaller)->status)->toBe(BureauStaffStatus::Deactivated)
        ->and($telecaller->refresh()->session_epoch)->toBe($epoch + 1)
        ->and(TrustedDevice::query()->where('user_id', $telecaller->id)->exists())->toBeFalse()
        ->and(Profile::query()->whereKey(array_map(fn ($p) => $p->id, $clients))->whereNotNull('assigned_staff_id')->exists())->toBeFalse()
        ->and(app(StaffSeats::class)->activeStaff($broker))->toBe(0)
        ->and(AuditLog::query()->where('action', 'broker.staff_deactivated')->sole()->after['unassigned'])->toBe(2);

    Event::assertDispatched(ForceLogout::class, fn (ForceLogout $e): bool => $e->userId === $telecaller->id
        && $e->broadcastOn()[0]->name === 'private-App.Models.User.'.$telecaller->id
        && $e->broadcastWith() === ['scope' => ForceLogout::ALL]);
});

it('B.19: a deactivated staff member’s next request is signed out', function (): void {
    $owner = bureauLogin();
    $telecaller = bureauLogin(BureauRole::Telecaller, broker: staffOf($owner)->broker);
    actingAsBureau($telecaller);
    $this->get(brokerUrl())->assertOk();

    app(DeactivateStaff::class)->handle($owner, staffOf($telecaller)->code);

    $this->get(brokerUrl())->assertRedirect(route('login'));
    expect(auth('web')->check())->toBeFalse();
});

it('a second deactivation is refused; history stays (notes, created_by)', function (): void {
    $owner = bureauLogin();
    $broker = staffOf($owner)->broker;
    $entry = bureauLogin(BureauRole::DataEntry, broker: $broker);
    $client = managedProfile($broker, ['created_by_user_id' => $entry->id]);

    app(DeactivateStaff::class)->handle($owner, staffOf($entry)->code);

    expect(fn () => app(DeactivateStaff::class)->handle($owner, staffOf($entry)->code))->toThrow(StaffRefused::class)
        ->and($client->refresh()->created_by_user_id)->toBe($entry->id)
        ->and(BrokerStaff::query()->where('user_id', $entry->id)->exists())->toBeTrue();
});

it('every login gets a system-generated STF code; codes are unique', function (): void {
    $a = staffOf(bureauLogin());
    $b = staffOf(bureauLogin(BureauRole::Manager));

    expect($a->code)->toMatch('/^STF\d+$/')->and($b->code)->toMatch('/^STF\d+$/')->and($a->code)->not->toBe($b->code);
});
