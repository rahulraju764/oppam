<?php

declare(strict_types=1);

use App\Data\Audit\AuditFilterData;
use App\Domain\Audit\TimelineSubjects;
use App\Livewire\Admin\Audit\AuditLogIndex;
use App\Livewire\Admin\Audit\EntityTimeline;
use App\Livewire\Admin\Billing\OrderShow;
use App\Livewire\Admin\Brokers\BrokerShow;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Broker;
use App\Models\BrokerStaff;
use App\Models\BrokerStaffInvitation;
use App\Models\Payment;
use App\Models\Refund;
use App\Queries\Audit\AuditLogQuery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P9.2a — A12 screens: the audit log viewer (audit.view; read-only; drawer with the redacted diff;
| export button only with audit.export) and the per-entity timeline on the member / bureau / order
| pages (the host page's permission; found by code; #[Locked]).
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    Cache::flush();
});

function a12As(string $role): AdminUser
{
    $admin = adminWithRole($role);
    test()->actingAs($admin, 'admin');

    return $admin;
}

// ---- Audit log viewer --------------------------------------------------------------------------------

it('A12 viewer renders for audit.view with loading states, an empty state, and no export button without audit.export', function (): void {
    a12As('read_only');

    Livewire::test(AuditLogIndex::class)->assertOk()
        ->assertSee('Audit log')->assertSee('No entries')
        ->assertSeeHtml('wire:loading')
        ->assertDontSeeHtml('dusk="audit-export"');

    a12As('super_admin');
    Livewire::test(AuditLogIndex::class)->assertSeeHtml('dusk="audit-export"');
});

it('A12 viewer is forbidden without audit.view — on load and on every action', function (): void {
    a12As('support');

    Livewire::test(AuditLogIndex::class)->assertForbidden();
});

it('A12 viewer lists rows newest first and escapes everything it shows (reason, subject, diff values)', function (): void {
    a12As('read_only');
    auditRow(['action' => 'old.one', 'created_at' => now()->subDay()]);
    $evil = auditRow(['action' => 'new.one', 'reason' => '<script>alert(1)</script>', 'subject_label' => '<b>OPM1</b>',
        'after' => ['note' => '<img src=x onerror=alert(2)>']]);

    Livewire::test(AuditLogIndex::class)
        ->assertSeeInOrder(['new.one', 'old.one'])
        ->assertDontSeeHtml('<script>alert(1)</script>')->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->assertDontSeeHtml('<b>OPM1</b>')
        ->call('show', $evil->id)
        ->assertDispatched('open-drawer', name: 'audit-entry')
        ->assertDontSeeHtml('<img src=x onerror=alert(2)>')->assertSeeHtml('&lt;img src=x onerror=alert(2)&gt;');
});

it('A12 the drawer shows the redacted diff, the reason, IP and browser; a malformed id opens nothing', function (): void {
    a12As('read_only');
    $row = auditRow(['action' => 'members.suspended', 'reason' => 'Fake photos', 'ip_address' => '203.0.113.9', 'user_agent' => 'TestAgent/1.0',
        'before' => ['status' => 'ACTIVE', 'phone' => '+919812345678'], 'after' => ['status' => 'SUSPENDED', 'phone' => '+919812345678']]);

    Livewire::test(AuditLogIndex::class)->call('show', $row->id)
        ->assertSee('Fake photos')->assertSee('203.0.113.9')->assertSee('TestAgent/1.0')
        ->assertSee('SUSPENDED')->assertSee('Changed')->assertSee('••••5678')->assertDontSee('9812345678');

    Livewire::test(AuditLogIndex::class)->call('show', "x' OR 1=1")
        ->assertNotDispatched('open-drawer')->assertSet('entry', '');
});

it('A12 the viewer has no way to change the trail: no edit, delete or restore action exists', function (): void {
    // The component's own public methods (pagination comes from Livewire's WithPagination trait).
    $paging = collect((new ReflectionClass(\Livewire\WithPagination::class))->getMethods())->map->getName()->all();
    $actions = collect((new ReflectionClass(AuditLogIndex::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $m): bool => $m->class === AuditLogIndex::class && ! in_array($m->getName(), $paging, true))
        ->map->getName()->values()->all();

    expect($actions)->toEqualCanonicalizing(['mount', 'updated', 'clearFilters', 'show', 'export', 'render']);
});

it('A12 cursor pages: page 2 continues where page 1 stopped, in the same order', function (): void {
    a12As('read_only');
    foreach (range(1, AuditLogQuery::PER_PAGE + 5) as $i) {
        auditRow(['action' => 'row.'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'created_at' => now()->subMinutes(100 - $i)]);
    }
    $first = app(AuditLogQuery::class)->page(AuditFilterData::fromArray([]))['rows'];

    Livewire::test(AuditLogIndex::class)->assertSee('row.035')->assertDontSee('row.005')
        ->call('setPage', $first->nextCursor()?->encode(), 'after')
        ->assertSee('row.005')->assertSee('row.001')->assertDontSee('row.035');
});

it('A12 filters live in the URL and a filter change goes back to the first page', function (): void {
    a12As('read_only');
    auditRow(['action' => 'billing.refund_approved']);
    auditRow(['action' => 'brokers.suspended']);

    Livewire::withQueryParams(['action' => 'billing'])->test(AuditLogIndex::class)
        ->assertSee('billing.refund_approved')->assertDontSee('brokers.suspended')
        ->set('action', 'brokers')->assertSee('brokers.suspended')->assertDontSee('billing.refund_approved')
        ->call('clearFilters')->assertSee('billing.refund_approved')->assertSee('brokers.suspended')
        ->set('actor', 'ghost@oppam.test')->assertSee('Nothing matches');
});

it('A12 the viewer runs a bounded number of queries (no N+1 as rows and member actors grow)', function (): void {
    a12As('read_only');
    $make = function (int $n): void {
        foreach (range(1, $n) as $i) {
            $member = memberWithPhone('+91984440'.str_pad((string) random_int(1000, 9999), 4, '0'));
            auditRow(['actor_type' => App\Enums\AuditActorType::User, 'actor_id' => $member->id, 'subject_type' => 'Profile', 'subject_label' => $member->profile->code]);
        }
    };
    $make(2);

    DB::enableQueryLog();
    Livewire::test(AuditLogIndex::class)->assertOk();
    $few = count(DB::getQueryLog());
    $make(10);
    DB::flushQueryLog();
    Cache::flush();
    Livewire::test(AuditLogIndex::class)->assertOk();

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual($few);
});

// ---- Entity timeline -----------------------------------------------------------------------------

/**
 * One entity of each kind with a row about something it owns, plus a row about a different entity.
 *
 * @return array{0: string, 1: string, 2: string} [entity, code, the action that must show]
 */
function timelineFixture(string $entity): array
{
    $member = memberWithPhone('+919844411001');
    $other = memberWithPhone('+919844411002');
    auditRow(['action' => 'not.mine', 'subject_type' => 'User', 'subject_id' => $other->id, 'reason' => 'Another record']);

    return match ($entity) {
        'member' => (function () use ($member): array {
            $refund = Refund::factory()->create(['profile_id' => $member->profile->id, 'order_id' => ($order = paidOrder($member))->id,
                'payment_id' => Payment::factory()->create(['order_id' => $order->id])->id]);
            auditRow(['action' => 'billing.refund_approved', 'subject_type' => 'Refund', 'subject_id' => $refund->id]);

            return ['member', $member->profile->code, 'billing.refund_approved'];
        })(),
        'broker' => (function (): array {
            $broker = Broker::factory()->create();
            $staff = BrokerStaff::factory()->create(['broker_id' => $broker->id]);
            auditRow(['action' => 'broker.staff_deactivated', 'subject_type' => 'BrokerStaff', 'subject_id' => $staff->id]);

            return ['broker', $broker->code, 'broker.staff_deactivated'];
        })(),
        default => (function () use ($member): array {
            $order = paidOrder($member);
            $payment = Payment::factory()->create(['order_id' => $order->id]);
            auditRow(['action' => 'billing.payment_reconciled', 'subject_type' => 'Payment', 'subject_id' => $payment->id]);

            return ['order', $order->code, 'billing.payment_reconciled'];
        })(),
    };
}

/** entity => [a role with the host page's permission, a role without it] */
const TIMELINE_ROLES = ['member' => ['moderator', 'finance'], 'broker' => ['support', 'moderator'], 'order' => ['finance', 'moderator']];

it('A12 the timeline lists the rows about the entity and what it owns — never another record\'s', function (string $entity): void {
    [$kind, $code, $action] = timelineFixture($entity);
    a12As(TIMELINE_ROLES[$entity][0]);

    Livewire::test(EntityTimeline::class, ['entity' => $kind, 'code' => strtolower($code)])->assertOk()
        ->assertSee($action)->assertDontSee('not.mine')->assertDontSee('Another record');
})->with(['member', 'broker', 'order']);

it('A12 the timeline needs its page\'s permission, 404s an unknown code or kind', function (string $entity): void {
    [$kind, $code] = timelineFixture($entity);

    a12As(TIMELINE_ROLES[$entity][1]);
    Livewire::test(EntityTimeline::class, ['entity' => $kind, 'code' => $code])->assertForbidden();

    a12As(TIMELINE_ROLES[$entity][0]);
    Livewire::test(EntityTimeline::class, ['entity' => $kind, 'code' => 'ZZZ99999'])->assertNotFound();
    Livewire::test(EntityTimeline::class, ['entity' => 'admin', 'code' => $code])->assertNotFound();
})->with(['member', 'broker', 'order']);

it('A12 the timeline\'s entity and code are locked', function (string $property): void {
    [$kind, $code] = timelineFixture('member');
    a12As('super_admin');

    Livewire::test(EntityTimeline::class, ['entity' => $kind, 'code' => $code])->set($property, 'order');
})->with(['entity', 'code'])->throws(CannotUpdateLockedPropertyException::class);

it('A12 a member timeline is for member accounts only: a profile owned by a non-member account is a 404', function (): void {
    $account = memberWithPhone('+919844411004');
    $account->forceFill(['role' => App\Enums\UserRole::Broker])->save();
    a12As('super_admin');

    Livewire::test(EntityTimeline::class, ['entity' => 'member', 'code' => $account->profile->code])->assertNotFound();
});

it('A12 the "Open in the audit log" link shows only with audit.view and filters the viewer to exactly this timeline', function (): void {
    [$kind, $code, $action] = timelineFixture('member');

    a12As('moderator');
    Livewire::test(EntityTimeline::class, ['entity' => $kind, 'code' => $code])->assertDontSee('Open in the audit log');

    a12As('super_admin');
    Livewire::test(EntityTimeline::class, ['entity' => $kind, 'code' => $code])
        ->assertSee('Open in the audit log')->assertSeeHtml(e(route('admin.audit.index', ['entity' => 'member:'.$code])));
    Livewire::withQueryParams(['entity' => 'member:'.$code])->test(AuditLogIndex::class)
        ->assertSee($action)->assertDontSee('not.mine')->assertSee('Timeline of '.$code);
});

it('A12 the timeline is on the bureau page (its own tab) and on the order page', function (): void {
    $member = memberWithPhone('+919844411003');
    $order = paidOrder($member);
    $broker = Broker::factory()->create();
    a12As('super_admin');

    Livewire::withQueryParams(['tab' => 'timeline'])->test(BrokerShow::class, ['broker' => $broker->code])
        ->assertSet('tab', 'timeline')->assertSee('Audit timeline')->assertSeeHtml('entity-timeline');
    Livewire::test(OrderShow::class, ['order' => $order->code])->assertSee('Audit timeline')->assertSeeHtml('entity-timeline');
    expect(AuditLog::query()->count())->toBe(0);   // looking writes nothing
});

it('A12 a timeline that hits the per-type cap says older records are left out — on the timeline and in the viewer', function (): void {
    $broker = Broker::factory()->create();
    BrokerStaffInvitation::factory()->count(TimelineSubjects::CAP)->create(['broker_id' => $broker->id]);
    $small = Broker::factory()->create();

    a12As('super_admin');
    Livewire::test(EntityTimeline::class, ['entity' => 'broker', 'code' => $broker->code])
        ->assertSee('only the latest '.TimelineSubjects::CAP);
    Livewire::test(AuditLogIndex::class)->set('entity', 'broker:'.$broker->code)
        ->assertSee('only the latest '.TimelineSubjects::CAP);

    Livewire::test(EntityTimeline::class, ['entity' => 'broker', 'code' => $small->code])->assertDontSee('only the latest');
    Livewire::test(AuditLogIndex::class)->set('entity', 'broker:'.$small->code)->assertDontSee('only the latest');
});
