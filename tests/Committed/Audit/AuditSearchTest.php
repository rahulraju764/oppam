<?php

declare(strict_types=1);

use App\Data\Audit\AuditFilterData;
use App\Enums\AuditActorType;
use App\Queries\Audit\AuditLogQuery;

/*
| P9.2a — A12 audit search (PRD A12 "viewer with filters"): free text over the action, who did it,
| the subject and the reason, through the FULLTEXT index. InnoDB FULLTEXT sees committed rows only,
| so this file runs in the Committed suite (tests/CommittedTestCase: audit_logs truncated before
| and after each test; nothing else is written here).
*/

beforeEach(function (): void {
    auditRow(['action' => 'billing.refund_approved', 'reason' => 'Duplicate payment from the app', 'subject_label' => 'RFD100001']);
    auditRow(['action' => 'brokers.suspended', 'reason' => 'Fake profiles reported in Kochi', 'subject_label' => 'BRK1042',
        'actor_type' => AuditActorType::Admin, 'actor_label' => 'divya@oppam.test']);
    auditRow(['action' => 'members.suspended', 'reason' => 'കൊച്ചി spam reports', 'subject_label' => 'OPM12370']);
});

/** @return list<string> */
function searchActions(array $filters): array
{
    return collect(app(AuditLogQuery::class)->page(AuditFilterData::fromArray($filters))['rows']->items())->pluck('action')->sort()->values()->all();
}

it('A12 search matches the action, the reason, who did it and the subject', function (string $search, array $expected): void {
    expect(searchActions(['search' => $search]))->toBe($expected);
})->with([
    'a word in the action' => ['refund', ['billing.refund_approved']],
    'a word in the reason' => ['duplicate', ['billing.refund_approved']],
    'the admin' => ['divya', ['brokers.suspended']],
    'the subject code' => ['BRK1042', ['brokers.suspended']],
    'a prefix' => ['refu', ['billing.refund_approved']],
    'any case' => ['KOCHI', ['brokers.suspended']],
    'Malayalam' => ['കൊച്ചി', ['members.suspended']],
    'shared word' => ['suspended', ['brokers.suspended', 'members.suspended']],
]);

it('A12 search: every word must match', function (): void {
    expect(searchActions(['search' => 'suspended kochi']))->toBe(['brokers.suspended'])
        ->and(searchActions(['search' => 'refund kochi']))->toBe([]);
});

it('A12 search combines with the other filters', function (): void {
    expect(searchActions(['search' => 'suspended', 'action' => 'members']))->toBe(['members.suspended']);
});

it('A12 search: operators, quotes and SQL typed by the admin are plain words — never syntax, never an error', function (string $search): void {
    expect(fn () => searchActions(['search' => $search]))->not->toThrow(Throwable::class);
})->with([
    'boolean operators' => '+refund -kochi ~x <y >z',
    'unbalanced quote' => '"refund',
    'sql' => "refund' OR '1'='1",
    'match syntax' => ') AGAINST (\'x\' IN BOOLEAN MODE) OR (1=1',
    'wildcards only' => '* * *',
]);

it('A12 search with common words ("for the") still finds the row, and says those words were left out', function (): void {
    auditRow(['action' => 'support.ticket_closed', 'reason' => 'Member asked for help with the wizard.', 'subject_label' => 'TKT1001']);

    $page = app(AuditLogQuery::class)->page(AuditFilterData::fromArray(['search' => 'asked for help with the wizard']));

    expect(collect($page['rows']->items())->pluck('action')->all())->toBe(['support.ticket_closed'])
        ->and($page['ignoredTerms'])->toBeTrue()
        ->and(searchActions(['search' => 'refund for the duplicate payment']))->toBe(['billing.refund_approved']);
});

it('A12 search of short words only lists everything and says those words were left out', function (): void {
    $page = app(AuditLogQuery::class)->page(AuditFilterData::fromArray(['search' => 'to of']));

    expect($page['rows']->items())->toHaveCount(3)
        ->and($page['ignoredTerms'])->toBeTrue();
});
