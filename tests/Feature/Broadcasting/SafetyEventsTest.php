<?php

declare(strict_types=1);

use App\Events\Admin\UrgentCaseOpened;
use App\Events\Safety\ConnectionsChanged;
use App\Models\User;

/*
| P6.2 — the safety broadcasts: `.connections.changed` goes only to the member's own channel with
| no payload (a block stays silent); `.case.urgent` goes to `admin.queues` with the case code only.
| Channel authorization for both is the existing UserChannel / AdminQueuesChannel (tested there).
*/

it('ConnectionsChanged: the member’s own private channel, broadcast as .connections.changed, empty payload, queued on broadcasts', function (): void {
    $event = new ConnectionsChanged('01hzzzzzzzzzzzzzzzzzzzzzzz');

    expect(collect($event->broadcastOn())->map->name->all())->toBe(['private-App.Models.User.01hzzzzzzzzzzzzzzzzzzzzzzz'])
        ->and($event->broadcastAs())->toBe('connections.changed')
        ->and($event->broadcastWith())->toBe([])
        ->and($event->queue)->toBe('broadcasts');
});

it('UrgentCaseOpened: admin.queues only, the case code and nothing about the member', function (): void {
    $event = new UrgentCaseOpened('CASE10001');

    expect(collect($event->broadcastOn())->map->name->all())->toBe(['private-admin.queues'])
        ->and($event->broadcastAs())->toBe('case.urgent')
        ->and($event->broadcastWith())->toBe(['case' => 'CASE10001']);
});

it('another member can’t join a member’s channel to hear .connections.changed', function (): void {
    seedMasters();
    $owner = groom();
    $other = bride();

    expect((new App\Broadcasting\UserChannel)->join($other, $owner->id))->toBeFalse()
        ->and((new App\Broadcasting\UserChannel)->join($owner, $owner->id))->toBeTrue();
    expect(User::query()->count())->toBe(2);
});
