<?php

declare(strict_types=1);

use App\Actions\Admin\Billing\ManageRefund;
use App\Enums\InvestigationType;
use App\Enums\OrderStatus;
use App\Enums\PlanCode;
use App\Enums\RefundCategory;
use App\Enums\RefundStatus;
use App\Jobs\Billing\GenerateInvoicePdf;
use App\Livewire\Admin\Billing\CouponsIndex;
use App\Livewire\Admin\Billing\Investigations;
use App\Livewire\Admin\Billing\OrderShow;
use App\Livewire\Admin\Billing\OrdersIndex;
use App\Livewire\Admin\Billing\PlanEditor;
use App\Livewire\Admin\Billing\PlansIndex;
use App\Livewire\Admin\Billing\RefundsIndex;
use App\Livewire\Admin\Billing\StuckOrders;
use App\Livewire\Admin\Billing\SubscriptionsIndex;
use App\Models\BillingInvestigation;
use App\Models\Coupon;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P5.3 — the A06 screens: render, empty states, permission by route and by action, tampered locked
| properties, and what they never show.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
    Queue::fake([GenerateInvoicePdf::class]);
    $this->finance = adminWithRole('finance');
    $this->actingAs($this->finance, 'admin');
});

const BILLING_PAGES = ['/billing/orders', '/billing/orders/stuck', '/billing/refunds', '/billing/reconciliation', '/billing/subscriptions', '/billing/plans', '/billing/plans/GOLD', '/billing/coupons'];

it('A06: every billing page needs billing.view (moderator 403, guest to sign-in); finance and support open them', function (string $path): void {
    signInAdmin($this, $this->finance);
    $this->get(adminUrl($path))->assertOk()->assertSee('<h1', false);

    signInAdmin($this, adminWithRole('support'));
    $this->get(adminUrl($path))->assertOk();

    signInAdmin($this, adminWithRole('moderator'));
    $this->get(adminUrl($path))->assertForbidden();
})->with(BILLING_PAGES);

it('a guest is sent to the admin sign-in', function (string $path): void {
    auth('admin')->logout();

    expect((string) $this->get(adminUrl($path))->assertRedirect()->headers->get('Location'))->toContain('login');
})->with(BILLING_PAGES);

it('empty states: lists with nothing to show say so', function (): void {
    Livewire::test(OrdersIndex::class)->assertSee('No orders found');
    Livewire::test(StuckOrders::class)->assertSee('No stuck orders');
    Livewire::test(RefundsIndex::class)->assertSee('Nothing here');
    Livewire::test(Investigations::class)->assertSee('Nothing to investigate');
    Livewire::test(CouponsIndex::class)->assertSee('No coupons yet');
    Livewire::test(SubscriptionsIndex::class)->assertSee('Nothing here');
});

it('orders list: filters by status and code; the unknown status is ignored', function (): void {
    $member = groom();
    $paid = checkoutOrder($member, PlanCode::Gold);
    capturePayment($paid);
    $open = checkoutOrder(bride(), PlanCode::Silver);

    Livewire::test(OrdersIndex::class)->assertSee($paid->code)->assertSee($open->code)
        ->set('status', OrderStatus::Paid->value)->assertSee($paid->code)->assertDontSee($open->code)
        ->set('status', 'nonsense')->assertSee($open->code)
        ->set('status', '')->set('q', $member->profile->code)->assertSee($paid->code)->assertDontSee($open->code);
});

it('order page: 404 for an unknown code; tampered code refused; webhooks shown without payer details; timeline', function (): void {
    $order = checkoutOrder(groom(), PlanCode::Gold);
    capturePayment($order);

    signInAdmin($this, $this->finance);
    $this->get(adminUrl('/billing/orders/ORD99999999'))->assertNotFound();

    $page = Livewire::test(OrderShow::class, ['order' => $order->code])
        ->assertSee('Paid, plan activated')->assertSee('payment.captured')->assertDontSee('[object');

    expect(fn () => $page->set('code', 'ORD1'))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('order page: refund request by the form; a bad amount is a field error; support has no button and is refused', function (): void {
    $order = checkoutOrder(groom(), PlanCode::Gold);
    capturePayment($order);
    $payment = Payment::query()->where('order_id', $order->id)->sole();

    Livewire::test(OrderShow::class, ['order' => $order->code])
        ->call('openRefund', $payment->gateway_payment_id)->assertDispatched('open-modal', name: 'refund-request')
        ->assertSet('refundAmount', number_format($payment->amount_paise / 100, 2, '.', ''))
        ->set('refundAmount', 'lots')->set('reason', 'Member asked')->call('requestRefund')->assertHasErrors('refundAmount')
        ->set('refundAmount', '10.50')->call('requestRefund')->assertHasNoErrors()->assertDispatched('toast');

    expect(App\Models\Refund::query()->sole()->amount_paise)->toBe(1050);

    $this->actingAs(adminWithRole('support'), 'admin');
    Livewire::test(OrderShow::class, ['order' => $order->code])->assertDontSeeHtml('wire:click="openRefund')
        ->call('openRefund', $payment->gateway_payment_id)->assertForbidden();
});

it('order page: another order’s payment id is a 404', function (): void {
    $order = checkoutOrder(groom(), PlanCode::Gold);
    $other = checkoutOrder(bride(), PlanCode::Gold);
    capturePayment($other);
    $foreign = Payment::query()->where('order_id', $other->id)->sole();

    Livewire::test(OrderShow::class, ['order' => $order->code])->call('openRefund', $foreign->gateway_payment_id)->assertNotFound();
});

it('order page: stuck actions by permission; force-activate has no button for finance', function (): void {
    $order = checkoutOrder(groom(), PlanCode::Gold);

    Livewire::test(OrderShow::class, ['order' => $order->code])
        ->assertSee('Re-poll gateway')->assertDontSee('Force-activate')
        ->set('reason', 'Not paid')->call('forceActivate')->assertForbidden();

    Livewire::test(OrderShow::class, ['order' => $order->code])
        ->set('reason', 'Member gave up')->call('markFailed')->assertDispatched('toast');
    expect($order->refresh()->status)->toBe(OrderStatus::Failed);
});

it('refunds page: approve is offered to another admin, withdraw to the requester; refused own approval is a toast', function (): void {
    $order = checkoutOrder(groom(), PlanCode::Gold);
    capturePayment($order);
    $payment = Payment::query()->where('order_id', $order->id)->sole();
    $refund = app(ManageRefund::class)->request($this->finance, $order->refresh(), $payment->gateway_payment_id, RefundCategory::Goodwill, 1000, 'Member asked');

    Livewire::test(RefundsIndex::class)->assertSee($refund->code)->assertSeeHtml('wire:click="withdraw')->assertDontSeeHtml('refund-approve-open')
        ->call('confirm', $refund->code, 'refund-approve')->call('approve')->assertDispatched('toast', type: 'danger');
    expect($refund->refresh()->status)->toBe(RefundStatus::Requested);

    $this->actingAs(adminWithRole('finance'), 'admin');
    $page = Livewire::test(RefundsIndex::class)->assertDontSeeHtml('wire:click="withdraw')
        ->call('confirm', $refund->code, 'refund-approve')->call('approve')->assertDispatched('toast', type: 'success');
    expect($refund->refresh()->status)->toBe(RefundStatus::Processed);

    expect(fn () => $page->set('selected', 'RFD1'))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('plan editor: live preview and warning; saves prices and entitlements; the Free plan has no prices; support can’t save', function (): void {
    $page = Livewire::test(PlanEditor::class, ['plan' => 'GOLD'])->assertSee('Enforced entitlements')
        ->set('name', 'Gold Plus')->assertSee('Gold Plus')
        ->set('features', '999 likes a day')->assertSee('999 likes a day');
    $page->assertSee('is enforced at');

    $page->set('prices.1.rupees', '1234')->call('saveCard')->assertHasNoErrors()->assertDispatched('toast');
    $plan = Plan::query()->where('code', 'GOLD')->with('prices')->sole();
    expect($plan->name)->toBe('Gold Plus')->and($plan->prices->firstWhere('duration_months', 1)?->price_paise)->toBe(123_400);

    $page->set('prices.1.rupees', 'abc')->call('saveCard')->assertHasErrors('prices.1');
    $page->set('rules.likes_per_day.limit', 'abc')->call('saveEntitlements')->assertHasErrors('rules.likes_per_day');
    $page->set('rules.likes_per_day.limit', '9')->call('saveEntitlements')->assertHasNoErrors();
    expect($plan->features()->where('entitlement', 'likes_per_day')->sole()->limit_value)->toBe(9);

    expect(fn () => $page->set('code', 'FREE'))->toThrow(CannotUpdateLockedPropertyException::class);

    Livewire::test(PlanEditor::class, ['plan' => 'FREE'])->assertDontSee('On sale');
    signInAdmin($this, $this->finance);
    $this->get(adminUrl('/billing/plans/NOPE'))->assertNotFound();

    $this->actingAs(adminWithRole('support'), 'admin');
    Livewire::test(PlanEditor::class, ['plan' => 'GOLD'])->assertDontSee('Save entitlements')->call('saveEntitlements')->assertForbidden();
});

it('plans page: deactivate through the typed-reason dialog; delete refused for a plan in use is a toast', function (): void {
    checkoutOrder(groom(), PlanCode::Gold);

    Livewire::test(PlansIndex::class)->assertSee('Gold')
        ->call('confirm', 'GOLD', 'plan-delete')->set('reason', 'Tidying up')->call('delete')->assertDispatched('toast', type: 'danger')
        ->call('confirm', 'GOLD', 'plan-deactivate')->set('reason', '')->call('deactivate')->assertHasErrors('reason')
        ->set('reason', 'New offer coming')->call('deactivate')->assertDispatched('toast', type: 'success');

    expect(Plan::query()->where('code', 'GOLD')->sole()->is_active)->toBeFalse();

    $this->actingAs(adminWithRole('support'), 'admin');
    Livewire::test(PlansIndex::class)->assertDontSeeHtml('wire:click="confirm')->call('confirm', 'SILVER', 'plan-deactivate')->assertForbidden();
});

it('coupons page: create, edit, deactivate; field errors; support is refused', function (): void {
    Livewire::test(CouponsIndex::class)->call('create')->assertDispatched('open-modal', name: 'coupon-form')
        ->set('code', 'x')->set('value', '10')->call('save')->assertHasErrors('code')
        ->set('code', 'welcome5')->set('value', 'lots')->call('save')->assertHasErrors('value')
        ->set('value', '5')->call('save')->assertHasNoErrors()->assertSee('WELCOME5');

    $page = Livewire::test(CouponsIndex::class)->call('edit', 'WELCOME5')->assertSet('value', '5')
        ->set('value', '7')->call('save')->assertHasNoErrors()
        ->call('toggle', 'WELCOME5');

    $coupon = Coupon::query()->sole();
    expect($coupon->value)->toBe(7)->and($coupon->is_active)->toBeFalse();
    expect(fn () => $page->set('editing', 'OTHER'))->toThrow(CannotUpdateLockedPropertyException::class);

    $this->actingAs(adminWithRole('support'), 'admin');
    Livewire::test(CouponsIndex::class)->assertDontSee('New coupon')->call('create')->assertForbidden();
});

it('subscriptions page: the five views; complimentary grant by code needs billing.force_activate', function (): void {
    $member = groom();
    capturePayment(checkoutOrder($member, PlanCode::Gold));
    $code = $member->profile->code;

    Livewire::test(SubscriptionsIndex::class)->assertSee($code)
        ->set('view', 'expired')->assertDontSee($code)
        ->set('view', 'nonsense')->assertSet('view', 'active')->assertSee($code);

    // Finance holds no force_activate.
    Livewire::test(SubscriptionsIndex::class)->assertDontSee('Grant complimentary')
        ->set('grantCode', $code)->set('reason', 'Thank you gift')->call('grant')->assertForbidden();

    $this->actingAs(adminWithRole('super_admin'), 'admin');
    $other = bride();
    Livewire::test(SubscriptionsIndex::class)
        ->set('grantCode', 'OPM00000')->set('reason', 'Thank you gift')->call('grant')->assertHasErrors('grantCode')
        ->set('grantCode', $other->profile->code)->set('grantDays', '400')->call('grant')->assertHasErrors('grantDays')
        ->set('grantDays', '30')->call('grant')->assertHasNoErrors()->assertDispatched('toast');

    expect(Subscription::query()->where('profile_id', $other->profile->id)->sole()->source)->toBe(App\Enums\SubscriptionSource::Complimentary);
    Livewire::test(SubscriptionsIndex::class)->set('view', 'complimentary')->assertSee($other->profile->code)->assertDontSee($code);
});

it('reconciliation page: resolve needs billing.refund; support sees no button; a bad id is a 404', function (): void {
    BillingInvestigation::raise(InvestigationType::UnknownOrder, 'pay_x', ['amount_paise' => 10_000, 'gateway_order' => 'order_x']);
    $row = BillingInvestigation::query()->sole();

    Livewire::test(Investigations::class)->assertSee('Payment for an order we don’t have')->assertSee('₹100')
        ->call('confirm', 'not-an-id')->assertNotFound();

    Livewire::test(Investigations::class)->call('confirm', $row->id)->set('note', 'Refunded in Razorpay')->call('resolve')->assertDispatched('toast', type: 'success');

    $this->actingAs(adminWithRole('support'), 'admin');
    Livewire::test(Investigations::class)->set('status', 'RESOLVED')->assertSee('Refunded in Razorpay');
    BillingInvestigation::raise(InvestigationType::UnknownOrder, 'pay_y', ['amount_paise' => 1]);
    Livewire::test(Investigations::class)->assertDontSeeHtml('wire:click="confirm')->call('confirm', BillingInvestigation::query()->open()->sole()->id)->assertForbidden();
});

it('A06 work queues: stuck orders, refunds to approve and open reconciliation rows are counted, for billing.view only', function (): void {
    $order = checkoutOrder(groom(), PlanCode::Gold);
    capturePayment($order, $order->total_paise - 100);        // money held: stuck
    $paid = checkoutOrder(bride(), PlanCode::Gold);
    capturePayment($paid);
    $payment = Payment::query()->where('order_id', $paid->id)->sole();
    app(ManageRefund::class)->request($this->finance, $paid->refresh(), $payment->gateway_payment_id, RefundCategory::Goodwill, 1000, 'Member asked');
    BillingInvestigation::raise(InvestigationType::UnknownOrder, 'pay_x', ['amount_paise' => 1]);

    $badges = app(App\Queries\Admin\WorkQueues::class)->badges($this->finance);

    expect($badges['admin.billing.stuck']['count'])->toBe(1)
        ->and($badges['admin.billing.refunds']['count'])->toBe(1)
        ->and($badges['admin.billing.investigations']['count'])->toBe(1)
        ->and(app(App\Queries\Admin\WorkQueues::class)->badges(adminWithRole('moderator')))->not->toHaveKey('admin.billing.stuck');
});

it('A02: revenue shows for billing.view with dashboard.view; A03 subscriptions tab lists the member’s orders', function (): void {
    $member = groom();
    $order = checkoutOrder($member, PlanCode::Gold);
    capturePayment($order);

    Livewire::test(App\Livewire\Admin\Dashboard::class)->assertSee('Revenue today')->assertSee('Revenue this month');
    $this->actingAs(adminWithRole('super_admin'), 'admin');
    Livewire::withoutLazyLoading()->test(App\Livewire\Admin\Members\Tabs\SubscriptionsTab::class, ['code' => $member->profile->code])->assertSee($order->code);

    $this->actingAs(adminWithRole('moderator'), 'admin');
    Livewire::test(App\Livewire\Admin\Dashboard::class)->assertDontSee('Revenue today');
});
