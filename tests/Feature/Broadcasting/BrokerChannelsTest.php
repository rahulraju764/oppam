<?php

declare(strict_types=1);

use App\Broadcasting\BrokerChannel;
use App\Broadcasting\BrokerOwnerChannel;
use App\Broadcasting\UserChannel;
use App\Data\Realtime\RealtimeContextData;
use App\Enums\BrokerStatus;
use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Enums\UserStatus;
use App\Models\Broker;

/*
| P7.2 — broker channels (PRD §11A B.15): `broker-owner.{broker}` only for that bureau's ACTIVE
| Owner on an open bureau; bureau logins may join their own `App.Models.User.{id}` (live toasts)
| and never the online roster.
*/

it('broker-owner.{broker}: the bureau’s active Owner only', function (): void {
    $broker = Broker::factory()->create();
    $owner = bureauLogin(BureauRole::Owner, broker: $broker);
    $manager = bureauLogin(BureauRole::Manager, broker: $broker);
    $otherOwner = bureauLogin(BureauRole::Owner);
    $channel = new BrokerOwnerChannel;

    expect($channel->join($owner, $broker->id))->toBeTrue()
        ->and($channel->join($manager, $broker->id))->toBeFalse()
        ->and($channel->join($otherOwner, $broker->id))->toBeFalse()
        ->and($channel->join(groom(), $broker->id))->toBeFalse()
        ->and($channel->join(null, $broker->id))->toBeFalse()
        ->and($channel->join($owner, '01JUNKJUNKJUNKJUNKJUNKJUNK'))->toBeFalse();
});

it('broker-owner.{broker}: refused once the login is suspended, the Owner row deactivated, or the bureau closed', function (string $case): void {
    $broker = Broker::factory()->create();
    $owner = bureauLogin(BureauRole::Owner, broker: $broker);

    match ($case) {
        'suspended login' => $owner->forceFill(['status' => UserStatus::Suspended])->save(),
        'deactivated owner row' => $owner->brokerStaff->forceFill(['status' => BureauStaffStatus::Deactivated])->save(),
        'rejected bureau' => $broker->forceFill(['status' => BrokerStatus::Rejected])->save(),
        'deactivated bureau' => $broker->forceFill(['status' => BrokerStatus::Deactivated])->save(),
    };

    expect((new BrokerOwnerChannel)->join($owner->refresh(), $broker->id))->toBeFalse();
})->with(['suspended login', 'deactivated owner row', 'rejected bureau', 'deactivated bureau']);

it('App.Models.User.{id}: a bureau login joins its own channel only, while active', function (): void {
    $login = bureauLogin(BureauRole::Telecaller);
    $other = bureauLogin(BureauRole::Owner);
    $channel = new UserChannel;

    expect($channel->join($login, (string) $login->id))->toBeTrue()
        ->and($channel->join($other, (string) $login->id))->toBeFalse();

    $login->forceFill(['status' => UserStatus::Suspended])->save();
    expect($channel->join($login->refresh(), (string) $login->id))->toBeFalse();
});

it('bureau pages open the socket for the login’s own channel, never the online roster', function (): void {
    $login = bureauLogin(BureauRole::Owner);
    $this->actingAs($login, 'web');

    $context = RealtimeContextData::current();

    expect($context?->userId)->toBe((string) $login->id)->and($context->presence)->toBeFalse();
});

// ---- P7.3: broker.{broker} — the bureau's shared events (managed-profile moderation) ----------------

it('broker.{broker}: every ACTIVE login of that bureau, whatever the role; nobody else', function (): void {
    $broker = Broker::factory()->create();
    $channel = new BrokerChannel;

    foreach (BureauRole::cases() as $role) {
        expect($channel->join(bureauLogin($role, broker: $broker), $broker->id))->toBeTrue($role->value);
    }

    expect($channel->join(bureauLogin(BureauRole::Owner), $broker->id))->toBeFalse()   // another bureau
        ->and($channel->join(groom(), $broker->id))->toBeFalse()
        ->and($channel->join(null, $broker->id))->toBeFalse()
        ->and($channel->join(bureauLogin(BureauRole::Owner, broker: $broker), '01JUNKJUNKJUNKJUNKJUNKJUNK'))->toBeFalse();
});

it('broker.{broker}: refused for a suspended login, an invited or deactivated staff row, a closed bureau', function (string $case): void {
    $broker = Broker::factory()->create();
    $login = bureauLogin(BureauRole::Telecaller, broker: $broker);

    match ($case) {
        'suspended login' => $login->forceFill(['status' => UserStatus::Suspended])->save(),
        'invited staff row' => $login->brokerStaff->forceFill(['status' => BureauStaffStatus::Invited])->save(),
        'deactivated staff row' => $login->brokerStaff->forceFill(['status' => BureauStaffStatus::Deactivated])->save(),
        'rejected bureau' => $broker->forceFill(['status' => BrokerStatus::Rejected])->save(),
        'deactivated bureau' => $broker->forceFill(['status' => BrokerStatus::Deactivated])->save(),
    };

    expect((new BrokerChannel)->join($login->refresh(), $broker->id))->toBeFalse();
})->with(['suspended login', 'invited staff row', 'deactivated staff row', 'rejected bureau', 'deactivated bureau']);

it('broker.{broker} is registered with the web guard and authorised through /broadcasting/auth', function (): void {
    pusherBroadcasting();
    $broker = Broker::factory()->create();
    $login = bureauLogin(BureauRole::Manager, broker: $broker);

    $this->actingAs($login, 'web')
        ->post(memberUrl('/broadcasting/auth'), ['socket_id' => '1234.5678', 'channel_name' => 'private-broker.'.$broker->id])
        ->assertOk();

    $this->actingAs(bureauLogin(BureauRole::Owner), 'web')
        ->post(memberUrl('/broadcasting/auth'), ['socket_id' => '1234.5678', 'channel_name' => 'private-broker.'.$broker->id])
        ->assertForbidden();
});

it('ManagedProfileModerated carries the profile code and the decision only — no ids, names or reason', function (): void {
    $event = new App\Events\Broker\ManagedProfileModerated('01HBROKERULIDXXXXXXXXXXXXX', 'OPM12345', 'rejected');

    expect($event->broadcastWith())->toBe(['profile' => 'OPM12345', 'decision' => 'rejected'])
        ->and($event->broadcastOn()[0]->name)->toBe('private-broker.01HBROKERULIDXXXXXXXXXXXXX')
        ->and($event->broadcastAs())->toBe('profile.moderated')
        ->and($event->queue)->toBe('broadcasts');
});

it('P7.7 ImportCompleted carries the batch code, status and counts only — no ids, names or mobiles', function (): void {
    $event = new App\Events\Broker\ImportCompleted('01HBROKERULIDXXXXXXXXXXXXX', 'B-0042', 'READY', 100, 0, 240, 'DAILY_CAP');

    expect($event->broadcastWith())->toBe(['batch' => 'B-0042', 'status' => 'READY', 'imported' => 100, 'skipped' => 0, 'waiting' => 240, 'stopped' => 'DAILY_CAP'])
        ->and($event->broadcastOn()[0]->name)->toBe('private-broker.01HBROKERULIDXXXXXXXXXXXXX')
        ->and($event->broadcastAs())->toBe('import.completed')
        ->and($event->queue)->toBe('broadcasts')
        ->and($event)->toBeInstanceOf(Illuminate\Contracts\Events\ShouldDispatchAfterCommit::class);
});

it('P7.7 ImportsPaused carries the reason and the batch code only (R-M13-32 banner)', function (): void {
    $event = new App\Events\Broker\ImportsPaused('01HBROKERULIDXXXXXXXXXXXXX', 'AUTO', 'B-0042');

    expect($event->broadcastWith())->toBe(['reason' => 'AUTO', 'batch' => 'B-0042'])
        ->and($event->broadcastOn()[0]->name)->toBe('private-broker.01HBROKERULIDXXXXXXXXXXXXX')
        ->and($event->broadcastAs())->toBe('imports.paused')
        ->and($event->queue)->toBe('broadcasts')
        ->and($event)->toBeInstanceOf(Illuminate\Contracts\Events\ShouldDispatchAfterCommit::class);
});
