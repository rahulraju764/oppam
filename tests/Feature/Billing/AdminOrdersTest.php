<?php

declare(strict_types=1);

use App\Actions\Admin\Billing\ManageRefund;
use App\Actions\Admin\Billing\ResolveInvestigation;
use App\Actions\Admin\Billing\ResolveStuckOrder;
use App\Actions\Billing\ActivateSubscription;
use App\Contracts\PaymentGateway;
use App\Enums\InvestigationStatus;
use App\Enums\InvestigationType;
use App\Enums\OrderStatus;
use App\Enums\PlanCode;
use App\Enums\RefundCategory;
use App\Enums\RefundStatus;
use App\Enums\SubscriptionStatus;
use App\Exceptions\Admin\BillingRefused;
use App\Exceptions\Billing\PaymentGatewayUnavailable;
use App\Jobs\Billing\GenerateInvoicePdf;
use App\Jobs\Billing\ReconcilePayments;
use App\Models\AuditLog;
use App\Models\BillingInvestigation;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Subscription;
use App\Queries\Admin\RevenueSummary;
use App\Queries\Billing\StuckOrdersQuery;
use Database\Seeders\PlansSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

/*
| P5.3 — A06 stuck orders (re-poll, mark failed, force-activate super admin only), the daily
| reconciliation's "investigate" rows, the monthly GST CSV and the A02 revenue figures.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
    Queue::fake([GenerateInvoicePdf::class]);
    $this->finance = adminWithRole('finance');
    $this->super = adminWithRole('super_admin');
});

/** An order whose capture came in for the wrong amount: money held, not activated. */
function mismatchedOrder(): Order
{
    $order = checkoutOrder(groom(), PlanCode::Gold);
    capturePayment($order, $order->total_paise - 100);

    return $order->refresh();
}

function runReconciliation(): void
{
    (new ReconcilePayments)->handle(app(PaymentGateway::class), app(ActivateSubscription::class));
}

it('A06 stuck orders: money held or an order open past expiry are listed; a paid or waiting order isn’t', function (): void {
    $mismatch = mismatchedOrder();
    $late = checkoutOrder(bride(), PlanCode::Gold);
    $late->forceFill(['expires_at' => now()->subMinute()])->save();
    $waiting = checkoutOrder(memberWithPhone('+919811100501'), PlanCode::Gold);
    $paid = checkoutOrder(memberWithPhone('+919811100502'), PlanCode::Gold);
    capturePayment($paid);

    $codes = app(StuckOrdersQuery::class)->query()->pluck('code');

    expect($codes)->toContain($mismatch->code)->toContain($late->code)
        ->not->toContain($waiting->code)->not->toContain($paid->code);
});

it('A06 re-poll: a capture whose webhook was lost activates the order, once; audited; billing.refund only', function (): void {
    $order = checkoutOrder(groom(), PlanCode::Gold);
    fakeGateway()->capture((string) $order->gateway_order_id);   // the webhook never arrives
    $resolve = app(ResolveStuckOrder::class);

    expect(fn () => $resolve->repoll(adminWithRole('support'), $order))->toThrow(AuthorizationException::class);

    expect($resolve->repoll($this->finance, $order)->status)->toBe(OrderStatus::Paid);
    $resolve->repoll($this->finance, $order->refresh());

    expect(Subscription::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'billing.order_repolled')->count())->toBe(2);
});

it('A06 mark failed: only with no money held, with a reason, audited', function (): void {
    $resolve = app(ResolveStuckOrder::class);
    $open = checkoutOrder(bride(), PlanCode::Gold);

    expect(fn () => $resolve->markFailed($this->finance, $open, ''))->toThrow(ValidationException::class)
        ->and(fn () => $resolve->markFailed($this->finance, mismatchedOrder(), 'Member gave up'))->toThrow(BillingRefused::class);

    expect($resolve->markFailed($this->finance, $open, 'Member gave up')->status)->toBe(OrderStatus::Failed)
        ->and(AuditLog::query()->where('action', 'billing.order_marked_failed')->sole()->reason)->toBe('Member gave up');

    $paid = checkoutOrder(memberWithPhone('+919811100502'), PlanCode::Gold);
    capturePayment($paid);
    expect(fn () => $resolve->markFailed($this->finance, $paid->refresh(), 'Trying anyway'))->toThrow(BillingRefused::class);
});

it('★ A06 force-activate: super admin only, needs a captured payment and a reason; activates once, audited with both amounts', function (): void {
    $resolve = app(ResolveStuckOrder::class);
    $order = mismatchedOrder();

    expect(fn () => $resolve->forceActivate($this->finance, $order, 'Finance tries'))->toThrow(AuthorizationException::class);

    // The permission alone isn't enough: a role given it is still refused.
    $granted = adminWithRole('finance');
    $granted->givePermissionTo('billing.force_activate');
    expect(fn () => $resolve->forceActivate($granted->refresh(), $order, 'Permission, wrong role'))->toThrow(BillingRefused::class, 'super admin')
        ->and(fn () => $resolve->forceActivate($this->super, $order, ''))->toThrow(ValidationException::class);

    expect($resolve->forceActivate($this->super, $order, 'Member confirmed the short payment')->status)->toBe(OrderStatus::Paid)
        ->and(Subscription::query()->where('order_id', $order->id)->sole()->status)->toBe(SubscriptionStatus::Active)
        ->and(AuditLog::query()->where('action', 'billing.order_force_activated')->sole()->after['captured_paise'])->toBe($order->total_paise - 100);

    expect(fn () => $resolve->forceActivate($this->super, $order->refresh(), 'A second time'))->toThrow(BillingRefused::class);

    $empty = checkoutOrder(bride(), PlanCode::Gold);
    expect(fn () => $resolve->forceActivate($this->super, $empty, 'Nothing captured'))->toThrow(BillingRefused::class);
});

it('A06 reconciliation: a capture we never heard about activates; a mismatch, an extra payment and a missing one become rows; a re-run adds nothing', function (): void {
    $today = now('Asia/Kolkata')->startOfDay();
    $this->travelTo($today->copy()->subMinutes(10));   // late yesterday (IST); the fake gateway's memory is short
    $lost = checkoutOrder(groom(), PlanCode::Gold);
    fakeGateway()->capture((string) $lost->gateway_order_id);
    $short = checkoutOrder(bride(), PlanCode::Gold);
    fakeGateway()->capture((string) $short->gateway_order_id, $short->total_paise - 100);
    $paid = checkoutOrder(memberWithPhone('+919811100502'), PlanCode::Gold);
    capturePayment($paid);
    $extra = fakeGateway()->capture((string) $paid->gateway_order_id);
    // Recorded here but never captured at the gateway: the fake's ledger doesn't hold it.
    $phantom = checkoutOrder(memberWithPhone('+919811100503'), PlanCode::Gold);
    Payment::factory()->create(['order_id' => $phantom->id, 'status' => App\Enums\PaymentStatus::Captured, 'captured_at' => now(), 'amount_paise' => $phantom->total_paise]);
    $this->travelTo($today->copy()->addMinutes(5));

    runReconciliation();
    runReconciliation();

    $rows = BillingInvestigation::query()->pluck('type')->map(fn (InvestigationType $t): string => $t->value)->sort()->values()->all();
    expect($lost->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($rows)->toBe(collect([InvestigationType::AmountMismatch->value, InvestigationType::ExtraPayment->value, InvestigationType::NotAtGateway->value])->sort()->values()->all())
        ->and(BillingInvestigation::query()->where('gateway_payment_id', $extra['payment_id'])->sole()->type)->toBe(InvestigationType::ExtraPayment);
});

it('A06 reconciliation: a gateway outage fails the job and writes nothing, so it retries; a refund unconfirmed after 48 h becomes a row', function (): void {
    $order = checkoutOrder(groom(), PlanCode::Gold);
    capturePayment($order);
    $payment = Payment::query()->where('order_id', $order->id)->sole();
    fakeGateway()->nextRefund('pending');
    $refunds = app(ManageRefund::class);
    $refund = $refunds->approve(adminWithRole('finance'), $refunds->request($this->finance, $order->refresh(), $payment->gateway_payment_id, RefundCategory::Goodwill, 1000, 'Partly back'));
    expect($refund->status)->toBe(RefundStatus::Processing);

    $this->travel(49)->hours();
    runReconciliation();
    runReconciliation();

    expect(BillingInvestigation::query()->where('type', InvestigationType::RefundStuck)->sole()->refund_id)->toBe($refund->id);

    $before = BillingInvestigation::query()->count();
    $down = Mockery::mock(PaymentGateway::class);
    $down->shouldReceive('paymentsBetween')->andThrow(PaymentGatewayUnavailable::because('timeout'));
    app()->instance(PaymentGateway::class, $down);

    expect(fn () => runReconciliation())->toThrow(PaymentGatewayUnavailable::class)
        ->and(BillingInvestigation::query()->count())->toBe($before);
});

it('A06 reconciliation is scheduled at 02:00 IST', function (): void {
    $event = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->description, 'ReconcilePayments'));

    expect($event?->expression)->toBe('0 2 * * *')->and($event?->timezone)->toBe('Asia/Kolkata');
});

it('A06 investigate rows: resolved once, with a note, audited; billing.refund only', function (): void {
    BillingInvestigation::raise(InvestigationType::UnknownOrder, 'pay_x', ['amount_paise' => 100]);
    $row = BillingInvestigation::query()->sole();
    $resolve = app(ResolveInvestigation::class);

    expect(fn () => $resolve->handle(adminWithRole('support'), $row, 'Not allowed'))->toThrow(AuthorizationException::class)
        ->and(fn () => $resolve->handle($this->finance, $row, ''))->toThrow(ValidationException::class);

    expect($resolve->handle($this->finance, $row, 'Refunded in the dashboard')->status)->toBe(InvestigationStatus::Resolved)
        ->and(fn () => $resolve->handle($this->finance, $row->refresh(), 'A second time'))->toThrow(BillingRefused::class)
        ->and(AuditLog::query()->where('action', 'billing.investigation_resolved')->sole()->actor_id)->toBe($this->finance->id);
});

it('A06 GST CSV: invoices and negative credit notes of the IST month, no names, audited; billing.export only, a bad month refused', function (): void {
    $member = groom();
    $order = checkoutOrder($member, PlanCode::Gold);
    capturePayment($order);
    $payment = Payment::query()->where('order_id', $order->id)->sole();
    $refunds = app(ManageRefund::class);
    $refunds->approve(adminWithRole('finance'), $refunds->request($this->finance, $order->refresh(), $payment->gateway_payment_id, RefundCategory::ServiceComplaint, 11_800, 'Partly back'));
    $month = now('Asia/Kolkata')->format('Y-m');

    signInAdmin($this, adminWithRole('support'));
    $this->get(adminUrl('/billing/gst-export?month='.$month))->assertForbidden();

    signInAdmin($this, $this->finance);
    $this->get(adminUrl('/billing/gst-export?month=nonsense'))->assertStatus(422);
    $response = $this->get(adminUrl('/billing/gst-export?month='.$month))->assertOk();
    $csv = $response->streamedContent();

    expect($csv)->toContain('INVOICE')->toContain('CREDIT_NOTE')->toContain('-118.00')->toContain($member->profile->code)->toContain('SERVICE_COMPLAINT')
        ->not->toContain($member->profile->first_name)
        ->and(AuditLog::query()->where('action', 'billing.gst_exported')->sole()->actor_id)->toBe($this->finance->id);
});

it('A02 revenue: paid orders less processed refunds, by IST day and month; by plan', function (): void {
    $order = checkoutOrder(groom(), PlanCode::Gold);
    capturePayment($order);
    $payment = Payment::query()->where('order_id', $order->id)->sole();
    $refunds = app(ManageRefund::class);
    $refunds->approve(adminWithRole('finance'), $refunds->request($this->finance, $order->refresh(), $payment->gateway_payment_id, RefundCategory::Goodwill, 10_000, 'Goodwill part'));

    $summary = app(RevenueSummary::class)->summary();
    $byPlan = app(RevenueSummary::class)->byPlan();

    expect($summary['today']->paise)->toBe($order->total_paise - 10_000)
        ->and($summary['month']->paise)->toBeGreaterThanOrEqual($summary['today']->paise)
        ->and($byPlan['labels'])->toContain($order->plan->name)
        ->and($byPlan['values'])->toContain(intdiv($order->total_paise, 100));
});

it('A03: the member search finds a member by order code', function (): void {
    $member = groom();
    $order = checkoutOrder($member, PlanCode::Gold);
    $this->actingAs(adminWithRole('super_admin'), 'admin');

    Livewire\Livewire::test(App\Livewire\Admin\Members\Index::class)->set('q', strtolower($order->code))->assertSee($member->profile->code);
});

it('Refund model links: a credit note can be downloaded with billing.view only', function (): void {
    $order = checkoutOrder(groom(), PlanCode::Gold);
    capturePayment($order);
    $payment = Payment::query()->where('order_id', $order->id)->sole();
    $refunds = app(ManageRefund::class);
    $refund = $refunds->approve(adminWithRole('finance'), $refunds->request($this->finance, $order->refresh(), $payment->gateway_payment_id, RefundCategory::Goodwill, 10_000, 'Goodwill part'));
    $number = Refund::query()->whereKey($refund->id)->with('creditNote')->sole()->creditNote?->number;

    signInAdmin($this, adminWithRole('moderator'));
    $this->get(adminUrl('/billing/credit-notes/'.$number))->assertForbidden();

    signInAdmin($this, $this->finance);
    $this->get(adminUrl('/billing/credit-notes/'.$number))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get(adminUrl('/billing/credit-notes/CN-2020-000999'))->assertNotFound();
});
