<?php

declare(strict_types=1);

use App\Broadcasting\BrokerChannel;
use App\Broadcasting\BrokerOwnerChannel;
use App\Enums\BureauRole;
use App\Models\User;

/*
| P7.9 — broker security sweep, channels (PRD §11A B.15, B.18). BrokerChannelsTest covers the bureau's
| own logins, other bureaus, suspended / deactivated logins and closed bureaus; this adds the
| attackers it didn't: a member account, every non-Owner role on the Owner channel, a bureau's id
| that doesn't exist, and the /broadcasting/auth endpoint for a guest.
*/

beforeEach(function (): void {
    seedMasters();
    $this->owner = bureauLogin();
    $this->broker = staffOf($this->owner)->broker;
});

it('★ B.15 a member account joins neither bureau channel', function (): void {
    $member = User::factory()->create();

    expect(app(BrokerChannel::class)->join($member, $this->broker->id))->toBeFalse()
        ->and(app(BrokerOwnerChannel::class)->join($member, $this->broker->id))->toBeFalse();
});

it('★ R-M13-24 broker-owner.{id}: no staff role of the bureau joins the Owner channel (earnings, payouts)', function (BureauRole $role): void {
    expect(app(BrokerOwnerChannel::class)->join(bureauLogin($role, broker: $this->broker), $this->broker->id))->toBeFalse();
})->with([BureauRole::Manager, BureauRole::DataEntry, BureauRole::Telecaller]);

it('B.15 an id that is no bureau joins nothing; a guest gets no channel auth', function (): void {
    expect(app(BrokerChannel::class)->join($this->owner, '01JZZZZZZZZZZZZZZZZZZZZZZZ'))->toBeFalse()
        ->and(app(BrokerOwnerChannel::class)->join($this->owner, '01JZZZZZZZZZZZZZZZZZZZZZZZ'))->toBeFalse();

    expect($this->post(memberUrl('/broadcasting/auth'), ['socket_id' => '1234.5678', 'channel_name' => 'private-broker-owner.'.$this->broker->id])->status())
        ->toBeIn([302, 401, 403]);
});
