<?php

declare(strict_types=1);

use App\Actions\Admin\Settings\UpdateSetting;
use App\Actions\Audit\ExportAuditLog;
use App\Data\Audit\AuditFilterData;
use App\Domain\Audit\AuditDiff;
use App\Enums\AuditActorType;
use App\Enums\SettingKey;
use App\Http\Controllers\Admin\AuditExportController;
use App\Livewire\Admin\Audit\AuditLogIndex;
use App\Models\AuditLog;
use App\Models\Broker;
use App\Models\BrokerStaff;
use App\Models\Payment;
use App\Queries\Audit\AuditLogQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/*
| P9.2a — A12 audit viewer (PRD A12 "viewer with filters and per-entity timeline", "before/after
| diff, redacted", append-only): the filters resolve to indexed columns and never list "everything"
| by mistake; the diff redacts again; the CSV export needs audit.export, is capped, neutralised,
| single-use and audited. Free-text (FULLTEXT) search needs committed rows: tests/Committed/Audit.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    Cache::flush();
    $this->admin = adminWithRole('super_admin');
    signInAdmin($this, $this->admin);
});

function auditFilter(array $values): AuditFilterData
{
    return AuditFilterData::fromArray($values);
}

/** @return list<string> the actions on the first page, newest first */
function auditActions(array $filters): array
{
    return collect(app(AuditLogQuery::class)->page(auditFilter($filters))['rows']->items())->pluck('action')->all();
}

// ---- Filters ---------------------------------------------------------------------------------------

it('A12 filters by who: an admin by email, a member by profile code, or the system', function (): void {
    $member = memberWithPhone('+919844400001');
    auditRow(['action' => 'by.admin', 'actor_type' => AuditActorType::Admin, 'actor_id' => $this->admin->id, 'actor_label' => $this->admin->email]);
    auditRow(['action' => 'by.member', 'actor_type' => AuditActorType::User, 'actor_id' => $member->id]);
    auditRow(['action' => 'by.system']);

    expect(auditActions(['actor' => $this->admin->email]))->toBe(['by.admin'])
        ->and(auditActions(['actor' => strtolower($member->profile->code)]))->toBe(['by.member'])
        ->and(auditActions(['actor' => 'System']))->toBe(['by.system']);
});

it('A12 an unknown actor, action, subject type or timeline record lists NOTHING and is reported back', function (string $key, string $value): void {
    auditRow(['action' => 'billing.refund_approved', 'subject_type' => 'Refund']);

    $page = app(AuditLogQuery::class)->page(auditFilter([$key => $value]));

    expect($page['rows']->items())->toBe([])
        ->and($page['unknown'])->toBe([$key]);
})->with([
    'unknown admin email' => ['actor', 'nobody@oppam.test'],
    'unknown member code' => ['actor', 'OPM99999999'],
    'action with SQL' => ['action', "billing' OR '1'='1"],
    'action with a wildcard' => ['action', 'billing%'],
    'subject type not in the trail' => ['subjectType', 'User; DROP TABLE audit_logs'],
    'timeline of an unknown member' => ['entity', 'member:OPM99999999'],
    'timeline of an unknown kind' => ['entity', 'admin:ADM1'],
    'timeline without a code' => ['entity', 'order:'],
]);

it('A12 an area lists its actions (prefix), a full key lists only that action', function (): void {
    auditRow(['action' => 'billing.refund_approved']);
    auditRow(['action' => 'billing.refund_requested']);
    auditRow(['action' => 'billingx.other']);
    auditRow(['action' => 'brokers.suspended']);

    expect(auditActions(['action' => 'billing']))->toEqualCanonicalizing(['billing.refund_approved', 'billing.refund_requested'])
        ->and(auditActions(['action' => 'BILLING.refund_approved']))->toBe(['billing.refund_approved']);
});

it('A12 an area with "_" matches that area only ("_" is not a LIKE wildcard here)', function (): void {
    auditRow(['action' => 'feature_flags.updated']);
    auditRow(['action' => 'featureXflags.updated']);

    expect(auditActions(['action' => 'feature_flags']))->toBe(['feature_flags.updated']);
});

it('A12 filters by subject type (from the trail\'s own list) and by subject code', function (): void {
    auditRow(['action' => 'a.one', 'subject_type' => 'Profile', 'subject_label' => 'OPM12370']);
    auditRow(['action' => 'a.two', 'subject_type' => 'Order', 'subject_label' => 'ORD100004']);

    expect(app(AuditLogQuery::class)->subjectTypes())->toBe(['Order', 'Profile'])
        ->and(auditActions(['subjectType' => 'Order']))->toBe(['a.two'])
        ->and(auditActions(['subject' => 'OPM12370']))->toBe(['a.one']);
});

it('A12 dates are IST days: 23:59 IST belongs to that day, 00:01 IST to the next', function (): void {
    auditRow(['action' => 'day.one', 'created_at' => '2026-11-10 18:29:00']);   // 23:59 IST on 10 Nov
    auditRow(['action' => 'day.two', 'created_at' => '2026-11-10 18:31:00']);   // 00:01 IST on 11 Nov

    expect(auditActions(['from' => '2026-11-10', 'to' => '2026-11-10']))->toBe(['day.one'])
        ->and(auditActions(['from' => '2026-11-11']))->toBe(['day.two'])
        ->and(auditActions(['from' => '10/11/2026']))->toHaveCount(2);   // not a date → no date filter
});

it('A12 the timeline filter lists exactly an entity\'s rows: a member, a bureau, an order', function (): void {
    $member = memberWithPhone('+919844400002');
    $other = memberWithPhone('+919844400003');
    $order = paidOrder($member);
    $payment = Payment::factory()->create(['order_id' => $order->id]);
    $broker = Broker::factory()->create();
    $staff = BrokerStaff::factory()->create(['broker_id' => $broker->id]);

    auditRow(['action' => 'member.user', 'subject_type' => 'User', 'subject_id' => $member->id]);
    auditRow(['action' => 'member.order', 'subject_type' => 'Order', 'subject_id' => $order->id]);
    auditRow(['action' => 'order.payment', 'subject_type' => 'Payment', 'subject_id' => $payment->id]);
    auditRow(['action' => 'other.member', 'subject_type' => 'User', 'subject_id' => $other->id]);
    auditRow(['action' => 'bureau.staff', 'subject_type' => 'BrokerStaff', 'subject_id' => $staff->id]);

    expect(auditActions(['entity' => 'member:'.$member->profile->code]))->toEqualCanonicalizing(['member.user', 'member.order'])
        ->and(auditActions(['entity' => 'order:'.$order->code]))->toEqualCanonicalizing(['member.order', 'order.payment'])
        ->and(auditActions(['entity' => 'broker:'.strtolower($broker->code)]))->toBe(['bureau.staff']);
});

it('A12 who did it is shown by email or member code — never a ULID', function (): void {
    $member = memberWithPhone('+919844400004');
    $rows = [
        auditRow(['actor_type' => AuditActorType::User, 'actor_id' => $member->id]),
        auditRow(['actor_type' => AuditActorType::Admin, 'actor_id' => $this->admin->id, 'actor_label' => $this->admin->email]),
        auditRow(),
    ];

    $names = app(AuditLogQuery::class)->actorNames($rows);

    expect(array_values($names))->toBe(['Member '.$member->profile->code, $this->admin->email, 'System'])
        ->and(implode(' ', $names))->not->toContain($member->id);
});

// ---- Diff ------------------------------------------------------------------------------------------

it('A12 the diff redacts again: a secret or phone stored in clear by an older row never shows', function (): void {
    $row = auditRow([
        'before' => ['status' => 'ACTIVE', 'phone' => '+919812345678', 'password' => 'hunter2', 'nested' => ['token' => 'abc', 'x' => 1]],
        'after' => ['status' => 'SUSPENDED', 'phone' => '+919812345678', 'nested' => ['token' => 'abc', 'x' => 2]],
    ]);

    $lines = collect(app(AuditDiff::class)->lines($row))->keyBy('key');
    $text = $lines->map(fn ($l): string => $l->before.' '.$l->after)->implode(' ');

    expect($text)->not->toContain('hunter2')->not->toContain('9812345678')->not->toContain('abc')
        ->and($lines['phone']->before)->toBe('••••5678')
        ->and($lines['password']->before)->toBe('[redacted]')
        ->and($lines['status']->changed)->toBeTrue()
        ->and($lines['phone']->changed)->toBeFalse()
        ->and($lines['nested.x']->after)->toBe('2')
        ->and($lines->values()->first()->changed)->toBeTrue();   // changed lines first
});

// ---- Export ----------------------------------------------------------------------------------------

it('A12 export: the viewer hands out a 5-minute signed, single-use link; the CSV is neutralised, redacted and audited', function (): void {
    auditRow(['action' => 'billing.refund_approved', 'reason' => '=HYPERLINK("http://evil","x")', 'subject_label' => '+SUM(A1)',
        'after' => ['phone' => '+919812345678', 'amount' => 999]]);
    auditRow(['action' => 'brokers.suspended']);

    $page = Livewire::test(AuditLogIndex::class)->set('action', 'billing')->call('export');
    $url = $page->effects['redirect'] ?? null;
    expect($url)->toContain('/audit/export');

    $response = $this->get($url)->assertOk();
    $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
    $csv = $response->streamedContent();

    expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($csv)->toContain("'=HYPERLINK")->toContain("'+SUM(A1)")->toContain('billing.refund_approved')
        ->and($csv)->not->toContain('brokers.suspended')->not->toContain('9812345678')->toContain('5678');
    expect(AuditLog::query()->where('action', 'audit.exported')->sole()->after)->toBe(['filters' => ['action' => 'billing'], 'rows' => 1]);

    $this->get($url)->assertNotFound();   // single use
});

it('A12 export link security: unsigned, tampered, another admin\'s token → refused', function (): void {
    Cache::put(AuditExportController::CACHE_PREFIX.str_repeat('a', 40), ['admin' => $this->admin->id, 'filters' => [], 'rows' => 1], 300);
    $signed = URL::temporarySignedRoute('admin.audit.export', now()->addMinutes(5), ['token' => str_repeat('a', 40)]);

    $this->get(route('admin.audit.export', ['token' => str_repeat('a', 40)]))->assertForbidden();   // unsigned
    $this->get($signed.'x')->assertForbidden();                                                     // tampered
    signInAdmin($this, adminWithRole('super_admin'));
    $this->get($signed)->assertNotFound();                                                          // not theirs
});

it('A12 export needs audit.export: refused for a read-only admin with audit.view — and the refusal is audited', function (): void {
    $reader = adminWithRole('read_only');
    signInAdmin($this, $reader);
    auditRow();

    expect($reader->can('audit.view'))->toBeTrue()->and($reader->can('audit.export'))->toBeFalse();
    expect(fn () => app(ExportAuditLog::class)->prepare($reader, auditFilter([])))->toThrow(AuthorizationException::class);
    expect(AuditLog::query()->where('action', 'audit.export_denied')->where('actor_id', $reader->id)->exists())->toBeTrue();

    Livewire::test(AuditLogIndex::class)->assertDontSeeHtml('dusk="audit-export"')
        ->call('export')->assertDispatched('toast', type: 'error')->assertNoRedirect();
});

it('A12 the export link re-checks audit.export when it is opened', function (): void {
    $reader = adminWithRole('read_only');
    Cache::put(AuditExportController::CACHE_PREFIX.str_repeat('b', 40), ['admin' => $reader->id, 'filters' => [], 'rows' => 1], 300);
    signInAdmin($this, $reader);

    $this->get(URL::temporarySignedRoute('admin.audit.export', now()->addMinutes(5), ['token' => str_repeat('b', 40)]))->assertForbidden();
});

it('A12 export is refused above system.audit_export_max_rows (narrow the filters), with no rows, or with a bad filter', function (): void {
    app(UpdateSetting::class)->handle($this->admin, SettingKey::SystemAuditExportMaxRows, '100', 'Test cap');
    foreach (range(1, 101) as $i) {
        auditRow(['action' => 'bulk.row']);
    }

    expect(fn () => app(ExportAuditLog::class)->prepare($this->admin, auditFilter(['action' => 'bulk'])))
        ->toThrow(ValidationException::class, 'Narrow the filters')
        ->and(fn () => app(ExportAuditLog::class)->prepare($this->admin, auditFilter(['action' => 'nothing'])))
        ->toThrow(ValidationException::class, 'Nothing to export')
        ->and(fn () => app(ExportAuditLog::class)->prepare($this->admin, auditFilter(['actor' => 'ghost@oppam.test'])))
        ->toThrow(ValidationException::class, 'Fix the filters');
    expect(app(ExportAuditLog::class)->prepare($this->admin, auditFilter(['action' => 'settings'])))->toBe(1);   // the cap change itself
});

it('A12 export is rate-limited: the 11th in an hour is refused', function (): void {
    auditRow();
    foreach (range(1, ExportAuditLog::EXPORTS_PER_HOUR) as $i) {
        app(ExportAuditLog::class)->prepare($this->admin, auditFilter([]));
    }

    expect(fn () => app(ExportAuditLog::class)->prepare($this->admin, auditFilter([])))->toThrow(TooManyRequestsHttpException::class);
});

it('A12 a throttled export is refused before the trail is counted', function (): void {
    auditRow();
    foreach (range(1, ExportAuditLog::EXPORTS_PER_HOUR) as $i) {
        app(ExportAuditLog::class)->prepare($this->admin, auditFilter([]));
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    expect(fn () => app(ExportAuditLog::class)->prepare($this->admin, auditFilter([])))->toThrow(TooManyRequestsHttpException::class);
    $counts = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains(strtolower($q['query']), 'count(*)') && str_contains($q['query'], 'audit_logs'));
    DB::disableQueryLog();

    expect($counts)->toBeEmpty();
});

it('A12 refused size checks are throttled too: after COUNTS_PER_HOUR "nothing to export" attempts no further COUNT runs', function (): void {
    foreach (range(1, ExportAuditLog::COUNTS_PER_HOUR) as $i) {
        expect(fn () => app(ExportAuditLog::class)->prepare($this->admin, auditFilter(['from' => '2000-01-01', 'to' => '2000-01-02'])))->toThrow(ValidationException::class);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    expect(fn () => app(ExportAuditLog::class)->prepare($this->admin, auditFilter(['from' => '2000-01-01', 'to' => '2000-01-02'])))->toThrow(TooManyRequestsHttpException::class);
    $counts = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains(strtolower($q['query']), 'count(*)') && str_contains($q['query'], 'audit_logs'));
    DB::disableQueryLog();

    expect($counts)->toBeEmpty();
});

// ---- HTTP ------------------------------------------------------------------------------------------

it('A12 the audit log sends guests to the admin sign-in', function (string $path): void {
    $this->app['auth']->forgetGuards();
    $this->flushSession();

    $this->get(adminUrl($path))->assertRedirect(route('admin.login'));
})->with(['/audit', '/audit/export']);

it('A12 the audit log needs audit.view: 403 without it, 200 with it (and in the sidebar)', function (): void {
    signInAdmin($this, adminWithRole('support'));
    $this->get(adminUrl('/audit'))->assertForbidden();

    signInAdmin($this, adminWithRole('read_only'));
    $this->get(adminUrl('/audit'))->assertOk()->assertSee('Audit log')->assertSee(route('admin.audit.index'));
});

it('A12 the trail stays append-only after the search indexes: the database still refuses update and delete', function (string $statement): void {
    auditRow(['action' => 'kept.forever']);

    expect(fn () => DB::statement($statement))->toThrow(Illuminate\Database\QueryException::class, 'append-only');
})->with([
    'update' => "UPDATE audit_logs SET reason = 'changed'",
    'delete' => 'DELETE FROM audit_logs',
]);
