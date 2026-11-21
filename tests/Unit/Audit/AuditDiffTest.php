<?php

declare(strict_types=1);

use App\Domain\Audit\AuditDiff;
use App\Queries\Audit\AuditLogQuery;

/*
| P9.2a — A12 viewer, pure parts: flattening a before / after for the diff, and turning the
| admin's free text into a safe FULLTEXT boolean expression.
*/

it('A12 diff: nested values flatten to dotted keys; lists stay whole', function (): void {
    expect(AuditDiff::flatten(['a' => 1, 'b' => ['c' => 2, 'd' => ['e' => 3]], 'tags' => ['x', 'y'], 'empty' => []]))
        ->toBe(['a' => 1, 'b.c' => 2, 'b.d.e' => 3, 'tags' => ['x', 'y'], 'empty' => []]);
});

it('A12 search: every word becomes a required prefix term; FTS operators are words, not syntax', function (): void {
    expect(AuditLogQuery::terms('Refund approved'))->toBe(['+refund* +approved*', false])
        ->and(AuditLogQuery::terms('+refund -spam "quoted" @>(x) ~y <z'))->toBe(['+refund* +spam* +quoted*', true])
        ->and(AuditLogQuery::terms("x' OR 1=1 --"))->toBe(['', true]);
});

it('A12 search: words shorter than 3 letters are dropped and reported; repeats collapse; at most 8 words', function (): void {
    expect(AuditLogQuery::terms('to be'))->toBe(['', true])
        ->and(AuditLogQuery::terms('refund refund'))->toBe(['+refund*', false])
        ->and(substr_count(AuditLogQuery::terms('aaa bbb ccc ddd eee fff ggg hhh iii jjj')[0], '+'))->toBe(8);
});

it('A12 search: InnoDB stopwords are dropped and reported (a required stopword would match no row)', function (): void {
    expect(AuditLogQuery::terms('refund for the duplicate payment'))->toBe(['+refund* +duplicate* +payment*', true])
        ->and(AuditLogQuery::terms('With THE'))->toBe(['', true])
        ->and(AuditLogQuery::terms('theme'))->toBe(['+theme*', false]);   // only whole stopwords
});

it('A12 search: Malayalam and other letters are kept', function (): void {
    expect(AuditLogQuery::terms('കൊച്ചി refund')[0])->toBe('+കൊച്ചി* +refund*');
});
