<?php

declare(strict_types=1);

use App\Models\Order;
use App\Queries\Audit\AuditLogQuery;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| P9.3c (P9.2a hand-over) — the A12 viewer's area / subject-type filter lists. Both come from a DISTINCT on
| the leading column of an existing index (a loose index scan, not a pass over the trail), are cached for a
| day, and a NEW area or subject type written through AuditLogger shows up at once (the stale list is dropped).
*/

beforeEach(function (): void {
    Cache::flush();
});

it('A12 filter lists: the areas and subject types present in the trail, sorted', function (): void {
    auditRow(['action' => 'billing.order_marked_failed', 'subject_type' => 'Order']);
    auditRow(['action' => 'billing.refund_approved', 'subject_type' => 'Order']);
    auditRow(['action' => 'members.suspended', 'subject_type' => 'Profile']);
    auditRow(['action' => 'system.webhook_replayed']);

    $audit = app(AuditLogQuery::class);

    expect($audit->areas())->toBe(['billing', 'members', 'system'])
        ->and($audit->subjectTypes())->toBe(['Order', 'Profile']);
});

it('A12 filter lists (P9.3c): cached, yet a new area or subject type written through AuditLogger appears at once', function (): void {
    auditRow(['action' => 'members.suspended', 'subject_type' => 'Profile']);
    $audit = app(AuditLogQuery::class);
    expect($audit->areas())->toBe(['members'])->and($audit->subjectTypes())->toBe(['Profile']);

    // Already-known area + type: the cached lists stay (no re-read needed).
    app(AuditLogger::class)->record('members.reactivated', null);
    expect(Cache::has(AuditLogQuery::AREAS_KEY))->toBeTrue();

    // A new area and a new subject type: both lists are dropped and re-read with them.
    seedAdminRoles();
    $order = Order::factory()->create();
    app(AuditLogger::class)->record('billing.order_repolled', $order, actor: adminWithRole('super_admin'));

    expect($audit->areas())->toBe(['billing', 'members'])
        ->and($audit->subjectTypes())->toBe(['Order', 'Profile']);
});

it('A12 filter lists (P9.3c EXPLAIN): both DISTINCTs read their leading-column index, never the whole table', function (): void {
    foreach (range(1, 30) as $i) {
        auditRow(['action' => 'area'.($i % 5).'.done', 'subject_type' => 'Type'.($i % 3)]);
    }

    $plan = fn (string $sql): object => DB::select('EXPLAIN '.$sql)[0];
    $actions = $plan('SELECT DISTINCT action FROM audit_logs');
    $types = $plan('SELECT DISTINCT subject_type FROM audit_logs WHERE subject_type IS NOT NULL ORDER BY subject_type');

    expect($actions->key)->toBe('audit_logs_action_created_at_index')
        ->and($types->key)->toBe('audit_logs_subject_type_subject_id_created_at_index');
});
