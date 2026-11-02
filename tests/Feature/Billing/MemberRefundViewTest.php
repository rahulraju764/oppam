<?php

declare(strict_types=1);

use App\Actions\Admin\Billing\ManageRefund;
use App\Enums\PlanCode;
use App\Enums\RefundCategory;
use App\Events\Admin\AdminRevenueChanged;
use App\Jobs\Billing\GenerateInvoicePdf;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Member\Billing\History;
use App\Models\CreditNote;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
| P5.3 close-out (decisions 2026-11-01): a member sees their refunds on /billing and downloads their
| own credit note; a paid order or processed refund refreshes open A02 dashboards, with no figures
| in the signal.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
    Queue::fake([GenerateInvoicePdf::class]);
    $this->finance = adminWithRole('finance');
    $this->approver = adminWithRole('finance');
});

function paidOrderOf(User $member): Order
{
    $order = checkoutOrder($member, PlanCode::Gold);
    capturePayment($order);

    return $order->refresh();
}

function refundRequested(Order $order, int $paise = 11_800): Refund
{
    $payment = Payment::query()->where('order_id', $order->id)->sole();

    return app(ManageRefund::class)->request(test()->finance, $order, $payment->gateway_payment_id, RefundCategory::Goodwill, $paise, 'Member asked');
}

it('/billing: a refund on its way and then refunded, with the credit note link', function (): void {
    $member = groom();
    $order = paidOrderOf($member);
    fakeGateway()->nextRefund('pending');
    $refund = app(ManageRefund::class)->approve($this->approver, refundRequested($order));

    $this->actingAs($member, 'web');
    Livewire::test(History::class)->assertSee('Refund on its way')->assertSeeHtml('&#8377;118')->assertDontSee('Credit note');

    postWebhook(fakeGateway()->refundWebhook((string) $refund->gateway_refund_id))->assertOk();
    $number = CreditNote::query()->where('refund_id', $refund->id)->sole()->number;
    Livewire::test(History::class)->assertSee('Refunded')->assertDontSee('Refund on its way')->assertSee($number)
        ->assertSeeHtml(route('member.billing.credit-note', ['number' => $number]));
});

it('/billing: a request still waiting for an admin, or rejected, is not shown; the reason never is', function (): void {
    $member = groom();
    $order = paidOrderOf($member);
    $waiting = refundRequested($order);

    $this->actingAs($member, 'web');
    Livewire::test(History::class)->assertDontSee('Refund')->assertDontSee('Member asked')->assertDontSee($waiting->code);

    app(ManageRefund::class)->reject($this->approver, $waiting, 'Outside the policy');
    Livewire::test(History::class)->assertDontSee('Refunded')->assertDontSee('Outside the policy');
});

it('/billing: a refund the gateway never answered isn’t promised to the member', function (): void {
    $member = groom();
    fakeGateway()->nextRefund('unavailable');
    app(ManageRefund::class)->approve($this->approver, refundRequested(paidOrderOf($member)));

    $this->actingAs($member, 'web');
    Livewire::test(History::class)->assertDontSee('Refund on its way')->assertDontSee('Refunded');
});

it('/billing: another member’s refund never shows; the page’s queries don’t grow with orders', function (): void {
    $member = groom();
    $other = bride();
    $theirs = paidOrderOf($other);
    app(ManageRefund::class)->approve($this->approver, refundRequested($theirs));

    $this->actingAs($member, 'web');
    Livewire::test(History::class)->assertDontSee($theirs->code)->assertDontSee('Refunded');

    paidOrderOf($member);
    DB::enableQueryLog();
    Livewire::test(History::class);
    $one = count(DB::getQueryLog());

    foreach (range(1, 3) as $i) {
        $order = paidOrderOf($member);
        app(ManageRefund::class)->approve($this->approver, refundRequested($order));
    }
    DB::flushQueryLog();
    Livewire::test(History::class);

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual($one + 3);   // the eager loads that only run once a refund exists
});

it('credit note download: the member’s own is a PDF; anyone else’s is 404; a guest signs in', function (): void {
    $member = groom();
    $refund = app(ManageRefund::class)->approve($this->approver, refundRequested(paidOrderOf($member)));
    $number = CreditNote::query()->where('refund_id', $refund->id)->sole()->number;
    $url = memberUrl('/billing/credit-notes/'.$number);

    $this->get($url)->assertRedirect();

    $this->actingAs($member, 'web');
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
    $this->get(memberUrl('/billing/credit-notes/CN-2020-000999'))->assertNotFound();

    $this->actingAs(bride(), 'web');
    $this->get($url)->assertNotFound();
});

it('A02 live revenue: a paid order and a processed refund send the signal on admin.dashboard, with no figures', function (): void {
    Event::fake([AdminRevenueChanged::class]);
    $order = paidOrderOf(groom());
    Event::assertDispatchedTimes(AdminRevenueChanged::class, 1);

    app(ManageRefund::class)->approve($this->approver, refundRequested($order));
    Event::assertDispatchedTimes(AdminRevenueChanged::class, 2);

    $signal = new AdminRevenueChanged;
    expect($signal->broadcastWith())->toBe([])
        ->and($signal->broadcastAs())->toBe('revenue.changed')
        ->and($signal->broadcastOn()[0]->name)->toBe('private-admin.dashboard');
});

it('A02 live revenue: only an admin who may see the figures listens; a request or rejection sends nothing', function (): void {
    $this->actingAs($this->finance, 'admin');
    expect((new Dashboard)->getListeners())->toHaveKey('echo-private:admin.dashboard,.revenue.changed');

    $this->actingAs(adminWithRole('moderator'), 'admin');
    expect((new Dashboard)->getListeners())->not->toHaveKey('echo-private:admin.dashboard,.revenue.changed');

    $order = paidOrderOf(groom());
    Event::fake([AdminRevenueChanged::class]);
    app(ManageRefund::class)->reject($this->approver, refundRequested($order), 'Outside the policy');
    Event::assertNotDispatched(AdminRevenueChanged::class);
});
