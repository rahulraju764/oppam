<?php

declare(strict_types=1);

use App\Enums\Entitlement;
use App\Enums\PlanCode;
use App\Livewire\Member\Billing\History;
use App\Models\Order;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
| P5.2 — /billing (PRD M10 "Billing history page: orders, invoices (download), current plan, usage
| meters"): the member's own data only, read-only (a support session may look), live on
| `.entitlements.changed`.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
});

it('/billing needs a signed-in member', function (): void {
    $this->get(memberUrl('/billing'))->assertRedirect(memberUrl('/login'));
    $this->actingAs(groom(), 'web')->get(memberUrl('/billing'))->assertOk()->assertSee('Membership &amp; billing', false);
});

it('M10: a Free member sees the Free plan, the meters (phone numbers "not in your plan") and an empty order list', function (): void {
    $member = groom();
    $this->actingAs($member, 'web');

    Livewire::test(History::class)
        ->assertSee('Free Membership')
        ->assertSee('Upgrade membership')
        ->assertSee('0 of 10 used · 10 left')          // likes per day
        ->assertSee('0 of 3 used · 3 left')            // interests per month
        ->assertSee('Not in your plan')                // verified phone numbers: 0
        ->assertSee('No payments yet');
});

it('M10: after paying, the plan, the meters as the Actions count them, and the order with its invoice', function (): void {
    $member = groom();
    $order = checkoutOrder($member, PlanCode::Gold, 3);
    capturePayment($order);
    app(App\Services\Entitlements\EntitlementService::class)->consume($member->profile->refresh(), Entitlement::InterestsPerMonth, 2);
    $invoice = $order->refresh()->invoice;
    $this->actingAs($member, 'web');

    Livewire::test(History::class)
        ->assertSee('Gold Membership')
        ->assertSee('Active until')
        ->assertSee('2 of 100 used · 98 left')
        ->assertSee('0 used · Unlimited')              // likes per day on Gold
        ->assertSee($order->code)
        ->assertSee('Gold · 3 months')
        ->assertSeeHtml(e(route('member.billing.invoice', ['invoice' => $invoice->number])))
        ->assertDontSee('No payments yet');
});

it('M10: a plan bought to follow the current one is shown as queued', function (): void {
    $member = groom();
    capturePayment(checkoutOrder($member, PlanCode::Gold, 1));
    capturePayment(checkoutOrder($member, PlanCode::Gold, 3));   // extends after the first
    $this->actingAs($member, 'web');

    Livewire::test(History::class)->assertSee('Then Gold from');
});

it('security: only the member\'s own orders are listed; orders that never reached the gateway are left out', function (): void {
    $member = groom();
    $other = bride();
    capturePayment($theirs = checkoutOrder($other));
    $failed = Order::factory()->create(['profile_id' => $member->profile->id, 'gateway_order_id' => null, 'code' => 'ORD900001']);
    $this->actingAs($member, 'web');

    Livewire::test(History::class)
        ->assertDontSee($theirs->code)
        ->assertDontSee($failed->code)
        ->assertSee('No payments yet');
});

it('orders are paginated 10 a page, newest first', function (): void {
    $member = groom();
    foreach (range(1, 12) as $i) {
        Order::factory()->create(['profile_id' => $member->profile->id, 'code' => 'ORD8000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            'created_at' => now()->subDays(20 - $i)]);
    }
    $this->actingAs($member, 'web');

    Livewire::test(History::class)
        ->assertSee('ORD800012')->assertDontSee('ORD800002')
        ->call('gotoPage', 2)
        ->assertSee('ORD800002')->assertDontSee('ORD800012');
});

it('A01: an admin support session can look (read-only page)', function (): void {
    $member = groom();
    chatImpersonate($member);
    $this->actingAs($member, 'web');

    Livewire::test(History::class)->assertOk()->assertSee('Free Membership');
});

it('re-renders on .entitlements.changed (the plan changed elsewhere)', function (): void {
    $member = groom();
    $this->actingAs($member, 'web');
    $page = Livewire::test(History::class)->assertSee('Free Membership');

    expect(array_keys($page->instance()->getListeners()))->toContain('echo-private:App.Models.User.'.$member->id.',.entitlements.changed');

    chatOnPlan($member, PlanCode::Silver);
    $page->dispatch('echo-private:App.Models.User.'.$member->id.',.entitlements.changed', ['plan' => 'SILVER'])
        ->assertSee('Silver Membership');
});
