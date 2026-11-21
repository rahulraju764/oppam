<?php

declare(strict_types=1);

use App\Domain\Audit\TimelineSubjects;

/*
| P9.2a — A12 per-entity timeline: owned-record lists are capped at TimelineSubjects::CAP ids, and
| capped() tells the screens when that cap was hit (older records are then left out).
*/

it('A12 timeline cap: capped() is true only when an owned-record list reaches CAP', function (array $subjects, bool $capped): void {
    expect(TimelineSubjects::capped($subjects))->toBe($capped);
})->with([
    'nothing owned' => [['Broker' => ['b1']], false],
    'one below the cap' => [['Broker' => ['b1'], 'BrokerStaff' => array_map('strval', range(1, TimelineSubjects::CAP - 1))], false],
    'at the cap' => [['Broker' => ['b1'], 'BrokerStaff' => array_map('strval', range(1, TimelineSubjects::CAP))], true],
]);
