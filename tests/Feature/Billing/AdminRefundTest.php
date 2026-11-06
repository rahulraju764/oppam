<?php

declare(strict_types=1);

use App\Actions\Admin\Billing\ManageRefund;
use App\Actions\Admin\Billing\ResolveStuckOrder;
use App\Actions\Billing\HandlePaymentWebhook;
use App\Actions\Billing\SettleRefund;
use App\Enums\InvestigationType;
use App\Enums\OrderStatus;
use App\Enums\PlanCode;
use App\Enums\ReferralSource;
use App\Enums\ReferralStatus;
use App\Enums\ReferralVoidReason;
use App\Enums\RefundCategory;
use App\Enums\RefundStatus;
use App\Enums\SubscriptionStatus;
use App\Exceptions\Admin\BillingRefused;
use App\Jobs\Billing\GenerateInvoicePdf;
use App\Models\AuditLog;
use App\Models\BillingInvestigation;
use App\Models\Broker;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Subscription;
use App\Notifications\Billing\RefundIssued;
use Database\Seeders\PlansSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

/*
| P5.3 — A06 refunds: one order at a time, by category, a second admin decides every one (decisions
| 2026-11-01). Side effects once only: credit note, plan ended, member told; commission voided when
| it is PROCESSED (§11A B.21 "a refund voids it and claws it back").
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
    Queue::fake([GenerateInvoicePdf::class]);
    $this->requester = adminWithRole('finance');
    $this->approver = adminWithRole('finance');
    $this->member = groom();
    $this->order = checkoutOrder($this->member, PlanCode::Gold);
    capturePayment($this->order);
    $this->order->refresh();
    $this->payment = Payment::query()->where('order_id', $this->order->id)->sole();
});

function askRefund(?int $paise = null, ?object $by = null, ?Payment $payment = null): Refund
{
    $test = test();

    return app(ManageRefund::class)->request(
        $by ?? $test->requester, $test->order->refresh(), ($payment ?? $test->payment)->gateway_payment_id,
        RefundCategory::Goodwill, $paise ?? $test->payment->amount_paise, 'Member asked within a day');
}

it('★ B.21 / A06: a real refund, end to end — credit note, plan ended, member told, commission voided, clawed back on the next run', function (): void {
    $broker = Broker::factory()->create();
    $referral = earnedCommission($broker, 60_000);
    $referral->forceFill(['order_id' => $this->order->id, 'profile_id' => $this->member->profile->id, 'source' => ReferralSource::Referral])->save();
    $finance = adminWithRole('finance');
    paidRun($finance);
    expect($referral->refresh()->status)->toBe(ReferralStatus::Paid);

    $refund = app(ManageRefund::class)->approve($this->approver, askRefund());

    // The gateway processed it.
    expect($refund->status)->toBe(RefundStatus::Processed)
        ->and($refund->effects_applied_at)->not->toBeNull()
        ->and($refund->decided_by_id)->toBe($this->approver->id)
        ->and(CreditNote::query()->where('refund_id', $refund->id)->sole()->total_paise)->toBe($this->order->total_paise)
        ->and(Subscription::query()->where('order_id', $this->order->id)->sole()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($this->member->profile->refresh()->is_premium)->toBeFalse();
    Notification::assertSentTo($this->member, RefundIssued::class, fn (RefundIssued $n): bool => $n->planEnded && $n->orderCode === $this->order->code);

    // The commission through the real event + queued listener, then the next run.
    expect($referral->refresh()->status)->toBe(ReferralStatus::Void)
        ->and($referral->void_reason)->toBe(ReferralVoidReason::Refund);
    $this->travel(1)->months();
    earnedCommission($broker, 150_000);
    expect(lineOf(createRun($finance), $broker)->clawback_paise)->toBe(60_000);
});

it('A06 / decisions 2026-11-01: the requester can’t approve or reject their own refund; another admin can; a second approval is refused', function (): void {
    $refund = askRefund();
    $refunds = app(ManageRefund::class);

    expect(fn () => $refunds->approve($this->requester, $refund))->toThrow(BillingRefused::class)
        ->and(fn () => $refunds->reject($this->requester, $refund, 'My own request'))->toThrow(BillingRefused::class)
        ->and($refund->refresh()->status)->toBe(RefundStatus::Requested);

    $refunds->approve($this->approver, $refund);

    expect(fn () => $refunds->approve(adminWithRole('finance'), $refund->refresh()))->toThrow(BillingRefused::class)
        ->and(CreditNote::query()->count())->toBe(1);
});

it('A06 permission: without billing.refund nothing can be requested or decided', function (): void {
    $support = adminWithRole('support');   // billing.view only
    $refund = askRefund();
    $refunds = app(ManageRefund::class);

    expect(fn () => $refunds->request($support, $this->order, $this->payment->gateway_payment_id, RefundCategory::Goodwill, 100, 'Not allowed here'))->toThrow(AuthorizationException::class)
        ->and(fn () => $refunds->approve($support, $refund))->toThrow(AuthorizationException::class)
        ->and(fn () => $refunds->reject($support, $refund, 'Not allowed here'))->toThrow(AuthorizationException::class)
        ->and(fn () => $refunds->withdraw($support, $refund))->toThrow(AuthorizationException::class)
        ->and($refund->refresh()->status)->toBe(RefundStatus::Requested);
});

it('A06 "one order at a time": a second refund waits until the first is finished', function (): void {
    askRefund(10_000);

    expect(fn () => askRefund(10_000))->toThrow(BillingRefused::class, 'still open');
});

it('never refunds more than is left, nor a payment of another order, nor without a reason', function (): void {
    $refunds = app(ManageRefund::class);

    expect(fn () => askRefund($this->payment->amount_paise + 1))->toThrow(BillingRefused::class)
        ->and(fn () => askRefund(99))->toThrow(ValidationException::class)
        ->and(fn () => $refunds->request($this->requester, $this->order, $this->payment->gateway_payment_id, RefundCategory::Goodwill, 1000, 'no'))->toThrow(ValidationException::class);

    $other = checkoutOrder(bride());
    capturePayment($other);
    $foreign = Payment::query()->where('order_id', $other->id)->sole();
    expect(fn () => $refunds->request($this->requester, $this->order, $foreign->gateway_payment_id, RefundCategory::Goodwill, 1000, 'Wrong order'))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('a partial refund keeps the plan; its credit note is its share; refunding the rest ends the plan and the notes add up to the invoice exactly', function (): void {
    $refunds = app(ManageRefund::class);
    $invoice = Invoice::query()->where('order_id', $this->order->id)->sole();
    $total = $this->payment->amount_paise;

    $first = $refunds->approve($this->approver, askRefund(33_333));

    expect($first->ends_plan)->toBeFalse()
        ->and(Subscription::query()->where('order_id', $this->order->id)->sole()->status)->toBe(SubscriptionStatus::Active)
        ->and($this->member->profile->refresh()->is_premium)->toBeTrue();

    $rest = $refunds->approve($this->approver, askRefund($total - 33_333));
    $notes = CreditNote::query()->where('invoice_id', $invoice->id)->get();

    expect($rest->ends_plan)->toBeTrue()
        ->and(Subscription::query()->where('order_id', $this->order->id)->sole()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($notes)->toHaveCount(2)
        ->and((int) $notes->sum('taxable_paise'))->toBe($invoice->taxable_paise)
        ->and((int) $notes->sum('total_paise'))->toBe($invoice->total_paise)
        ->and((int) $notes->sum(fn (CreditNote $n): int => $n->cgst_paise + $n->sgst_paise + $n->igst_paise))->toBe($invoice->total_paise - $invoice->taxable_paise)
        ->and(fn () => askRefund(100))->toThrow(BillingRefused::class);
});

it('A06 credit notes are gapless in their own series, by financial year', function (): void {
    $refunds = app(ManageRefund::class);
    $refunds->approve($this->approver, askRefund(10_000));
    $refunds->approve($this->approver, askRefund(10_000));

    $numbers = CreditNote::query()->orderBy('sequence')->pluck('number', 'sequence')->all();
    expect(array_keys($numbers))->toBe([1, 2])
        ->and(array_values($numbers)[0])->toMatch('/^CN-\d{4}-000001$/')
        ->and(strlen(array_values($numbers)[0]))->toBeLessThanOrEqual(16);
});

it('a refund of an extra payment never touches the plan and has no invoice to credit', function (): void {
    $extra = fakeGateway()->capture((string) $this->order->gateway_order_id);
    app(HandlePaymentWebhook::class)->handle($extra['body'], $extra['signature'], $extra['event_id']);
    $payment = Payment::query()->where('gateway_payment_id', $extra['payment_id'])->sole();

    $refund = app(ManageRefund::class)->approve($this->approver, askRefund($payment->amount_paise, payment: $payment));

    expect($refund->status)->toBe(RefundStatus::Processed)->and($refund->ends_plan)->toBeFalse()
        ->and(CreditNote::query()->count())->toBe(0)
        ->and(Subscription::query()->where('order_id', $this->order->id)->sole()->status)->toBe(SubscriptionStatus::Active);
});

it('a forged refund webhook is refused before anything is read; an unknown refund changes nothing', function (): void {
    $refund = askRefund();
    postWebhook(['body' => '{"event":"refund.processed"}', 'signature' => 'bad', 'event_id' => 'evt_forged'])->assertStatus(400);

    expect($refund->refresh()->status)->toBe(RefundStatus::Requested);

    // Signed, but for a refund made at the gateway itself: we have no row, so nothing moves.
    $unknown = fakeGateway()->refund($this->payment->gateway_payment_id, 500, 'not-ours');
    postWebhook(fakeGateway()->refundWebhook($unknown->id))->assertOk();
    expect($refund->refresh()->status)->toBe(RefundStatus::Requested)->and(Refund::query()->count())->toBe(1);

    // A signed report naming our refund while it still waits for a second admin moves nothing either.
    $early = fakeGateway()->refund($this->payment->gateway_payment_id, $refund->amount_paise, $refund->code);
    postWebhook(fakeGateway()->refundWebhook($early->id))->assertOk();
    expect($refund->refresh()->status)->toBe(RefundStatus::Requested)
        ->and(CreditNote::query()->count())->toBe(0)
        ->and(BillingInvestigation::query()->where('refund_id', $refund->id)->count())->toBe(1);
});

it('withdraw: only the requester, only while waiting; reject needs a note; both leave the money available', function (): void {
    $refunds = app(ManageRefund::class);
    $refund = askRefund();

    expect(fn () => $refunds->withdraw($this->approver, $refund))->toThrow(BillingRefused::class);
    expect($refunds->withdraw($this->requester, $refund)->status)->toBe(RefundStatus::Cancelled);

    $second = askRefund();
    expect(fn () => $refunds->reject($this->approver, $second, ''))->toThrow(ValidationException::class);
    expect($refunds->reject($this->approver, $second, 'Outside the policy')->status)->toBe(RefundStatus::Rejected)
        ->and(askRefund()->amount_paise)->toBe($this->payment->amount_paise);
});

it('every step is audited with its admin', function (): void {
    $refund = app(ManageRefund::class)->approve($this->approver, askRefund());

    expect(AuditLog::query()->where('action', 'billing.refund_requested')->sole()->actor_id)->toBe($this->requester->id)
        ->and(AuditLog::query()->where('action', 'billing.refund_approved')->sole()->actor_id)->toBe($this->approver->id)
        ->and($refund->order->status)->toBe(OrderStatus::Paid);
});

it('gateway accepts, then the webhook confirms: nothing happens until PROCESSED, then every effect once; a redelivery changes nothing', function (): void {
    $referral = earnedCommission(Broker::factory()->create(), 60_000);
    $referral->forceFill(['order_id' => $this->order->id])->save();
    fakeGateway()->nextRefund('pending');

    $refund = app(ManageRefund::class)->approve($this->approver, askRefund());

    expect($refund->status)->toBe(RefundStatus::Processing)
        ->and($refund->effects_applied_at)->toBeNull()
        ->and(CreditNote::query()->count())->toBe(0)
        ->and(Subscription::query()->where('order_id', $this->order->id)->sole()->status)->toBe(SubscriptionStatus::Active)
        ->and($referral->refresh()->status)->toBe(ReferralStatus::Earned);
    Notification::assertNotSentTo($this->member, RefundIssued::class);

    $delivery = fakeGateway()->refundWebhook((string) $refund->gateway_refund_id);
    postWebhook($delivery)->assertOk();
    postWebhook(['event_id' => 'evt_second'] + $delivery)->assertOk();

    expect($refund->refresh()->status)->toBe(RefundStatus::Processed)
        ->and(CreditNote::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'billing.refund_effects_applied')->count())->toBe(1)
        ->and(Subscription::query()->where('order_id', $this->order->id)->sole()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($referral->refresh()->status)->toBe(ReferralStatus::Void);
    Notification::assertSentToTimes($this->member, RefundIssued::class, 1);
});

it('★ no double refund: a gateway with no answer keeps the money reserved and the order blocked; nothing is applied; it can’t be requested again', function (): void {
    fakeGateway()->nextRefund('unavailable');

    $refund = app(ManageRefund::class)->approve($this->approver, askRefund());

    expect($refund->status)->toBe(RefundStatus::Processing)
        ->and($refund->failure_reason)->toBe(SettleRefund::GATEWAY_UNANSWERED)
        ->and($refund->effects_applied_at)->toBeNull()
        ->and(CreditNote::query()->count())->toBe(0)
        ->and(Subscription::query()->where('order_id', $this->order->id)->sole()->status)->toBe(SubscriptionStatus::Active)
        ->and(BillingInvestigation::query()->sole()->type)->toBe(InvestigationType::RefundStuck)
        ->and(fn () => askRefund())->toThrow(BillingRefused::class, 'still open');
});

it('★ no double refund: after no answer, the late webhook settles the same refund, once', function (): void {
    fakeGateway()->nextRefund('unavailable');
    $refund = app(ManageRefund::class)->approve($this->approver, askRefund());
    $late = fakeGateway()->refund($this->payment->gateway_payment_id, $refund->amount_paise, $refund->code);   // it did go through

    postWebhook(fakeGateway()->refundWebhook($late->id, 'refund.created'))->assertOk();
    expect($refund->refresh()->status)->toBe(RefundStatus::Processing)->and($refund->failure_reason)->toBeNull();

    postWebhook(fakeGateway()->refundWebhook($late->id))->assertOk();
    expect($refund->refresh()->status)->toBe(RefundStatus::Processed)->and(CreditNote::query()->count())->toBe(1);
});

it('closing an unanswered refund by hand: not sent frees the money (after the wait, Razorpay asked); sent settles like a webhook; a reason and a real id are required; billing.refund only', function (): void {
    $refunds = app(ManageRefund::class);
    fakeGateway()->nextRefund('unavailable');
    $first = $refunds->approve($this->approver, askRefund());

    expect(fn () => $refunds->closeAsNotSent(adminWithRole('support'), $first, 'Checked Razorpay'))->toThrow(AuthorizationException::class)
        ->and(fn () => $refunds->closeAsSent(adminWithRole('support'), $first, 'rfnd_abc123', 'Checked Razorpay'))->toThrow(AuthorizationException::class)
        ->and(fn () => $refunds->closeAsNotSent($this->approver, $first, ''))->toThrow(ValidationException::class)
        ->and(fn () => $refunds->closeAsSent($this->approver, $first, 'nonsense', 'Checked Razorpay'))->toThrow(ValidationException::class);

    $this->travel(31)->minutes();
    expect($refunds->closeAsNotSent($this->approver, $first, 'Not in Razorpay')->status)->toBe(RefundStatus::Failed)
        ->and(AuditLog::query()->where('action', 'billing.refund_closed_by_hand')->sole()->reason)->toBe('Not in Razorpay')
        ->and(BillingInvestigation::query()->where('refund_id', $first->id)->where('type', InvestigationType::RefundStuck)->sole()->status)->toBe(App\Enums\InvestigationStatus::Resolved)
        ->and(fn () => $refunds->closeAsNotSent($this->approver, $first->refresh(), 'A second time'))->toThrow(BillingRefused::class);

    // Made by hand in the Razorpay dashboard (no RFD code on it), then recorded here with its id.
    fakeGateway()->nextRefund('unavailable');
    $second = $refunds->approve($this->approver, askRefund());
    $byHand = fakeGateway()->plantRefund($this->payment->gateway_payment_id, $second->amount_paise, null);
    $closed = $refunds->closeAsSent($this->approver, $second, $byHand->id, 'Seen in Razorpay');

    expect($closed->status)->toBe(RefundStatus::Processed)->and($closed->gateway_refund_id)->toBe($byHand->id)
        ->and(CreditNote::query()->where('refund_id', $closed->id)->count())->toBe(1);
});

it('★ no double refund (owner 2026-11-03): a refund the gateway took but never answered can’t be closed as not sent — Razorpay is asked, and it is recorded as it stands there', function (): void {
    $refunds = app(ManageRefund::class);
    fakeGateway()->nextRefund('sent-unanswered');   // Razorpay made it; the answer was lost
    $refund = $refunds->approve($this->approver, askRefund());
    expect($refund->isUnanswered())->toBeTrue();

    $this->travel(31)->minutes();
    expect(fn () => $refunds->closeAsNotSent($this->approver, $refund, 'Nothing in Razorpay'))->toThrow(BillingRefused::class, 'so it was sent');

    $refund->refresh();
    expect($refund->status)->toBe(RefundStatus::Processing)->and($refund->gateway_refund_id)->not->toBeNull()
        ->and($refund->isUnanswered())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'billing.refund_closed_by_hand')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'billing.refund_found_at_gateway')->sole()->actor_id)->toBe($this->approver->id)
        ->and(BillingInvestigation::query()->where('refund_id', $refund->id)->where('type', InvestigationType::RefundStuck)->sole()->status)->toBe(App\Enums\InvestigationStatus::Resolved)
        ->and(fn () => askRefund())->toThrow(BillingRefused::class, 'still open');   // the money stays reserved: no second refund

    postWebhook(fakeGateway()->refundWebhook((string) $refund->gateway_refund_id))->assertOk();
    expect($refund->refresh()->status)->toBe(RefundStatus::Processed)->and(CreditNote::query()->count())->toBe(1);
});

it('★ no double refund (owner 2026-11-03): "not sent" waits billing.refund_not_sent_wait_minutes after approval; with Razorpay unreachable nothing is closed', function (): void {
    App\Models\Setting::factory()->keyed(App\Enums\SettingKey::BillingRefundNotSentWaitMinutes, 60)->create();
    App\Support\Facades\Settings::flush();
    $refunds = app(ManageRefund::class);
    fakeGateway()->nextRefund('unavailable');
    $refund = $refunds->approve($this->approver, askRefund());

    $this->travel(59)->minutes();
    expect(fn () => $refunds->closeAsNotSent($this->approver, $refund, 'Not in Razorpay'))->toThrow(BillingRefused::class, 'Wait until');

    $this->travel(2)->minutes();
    fakeGateway()->failNextRefundLookup();
    expect(fn () => $refunds->closeAsNotSent($this->approver, $refund, 'Not in Razorpay'))->toThrow(BillingRefused::class, 'can’t be reached')
        ->and($refund->refresh()->status)->toBe(RefundStatus::Processing);

    fakeGateway()->failNextRefundLookup();
    expect(fn () => $refunds->closeAsSent($this->approver, $refund, 'rfnd_abc123', 'Seen in Razorpay'))->toThrow(BillingRefused::class, 'can’t be reached')
        ->and($refund->refresh()->status)->toBe(RefundStatus::Processing)
        ->and(AuditLog::query()->where('action', 'billing.refund_closed_by_hand')->count())->toBe(0);

    expect($refunds->closeAsNotSent($this->approver, $refund, 'Not in Razorpay')->status)->toBe(RefundStatus::Failed);
});

it('closing as sent checks the id at Razorpay: unknown there, another amount, or still pending → refused, nothing closed', function (): void {
    $refunds = app(ManageRefund::class);
    fakeGateway()->nextRefund('unavailable');
    $stuck = $refunds->approve($this->approver, askRefund(10_000));
    $otherAmount = fakeGateway()->plantRefund($this->payment->gateway_payment_id, 9_000, null);
    $pending = fakeGateway()->plantRefund($this->payment->gateway_payment_id, 10_000, null, 'pending');

    expect(fn () => $refunds->closeAsSent($this->approver, $stuck, 'rfnd_neverexisted', 'Seen in Razorpay'))->toThrow(BillingRefused::class, 'has no refund')
        ->and(fn () => $refunds->closeAsSent($this->approver, $stuck, $otherAmount->id, 'Seen in Razorpay'))->toThrow(BillingRefused::class, 'is for ₹90')
        ->and(fn () => $refunds->closeAsSent($this->approver, $stuck, $pending->id, 'Seen in Razorpay'))->toThrow(BillingRefused::class, 'not paid')
        ->and($stuck->refresh()->status)->toBe(RefundStatus::Processing)->and($stuck->gateway_refund_id)->toBeNull()
        ->and(AuditLog::query()->where('action', 'billing.refund_closed_by_hand')->count())->toBe(0)
        ->and(CreditNote::query()->count())->toBe(0);

    // A dashboard refund recorded against one refund can't be quoted for another.
    $byHand = fakeGateway()->plantRefund($this->payment->gateway_payment_id, 10_000, null);
    expect($refunds->closeAsSent($this->approver, $stuck, $byHand->id, 'Seen in Razorpay')->status)->toBe(RefundStatus::Processed);
    fakeGateway()->nextRefund('unavailable');
    $another = $refunds->approve($this->approver, askRefund(10_000));
    expect(fn () => $refunds->closeAsSent($this->approver, $another, $byHand->id, 'Copied the same id'))->toThrow(BillingRefused::class, 'Another refund')
        ->and($another->refresh()->status)->toBe(RefundStatus::Processing);
});

it('★ no double refund: a refund the gateway accepted can’t be closed as not sent, so its money can’t be freed and refunded again', function (): void {
    $refunds = app(ManageRefund::class);
    fakeGateway()->nextRefund('pending');
    $refund = $refunds->approve($this->approver, askRefund());

    expect(fn () => $refunds->closeAsNotSent($this->approver, $refund, 'Clicked the wrong thing'))->toThrow(BillingRefused::class)
        ->and(fn () => $refunds->closeAsFailedAtGateway($this->approver, $refund, 'Too early to say'))->toThrow(BillingRefused::class)
        ->and(fn () => $refunds->closeAsSent($this->approver, $refund, (string) $refund->gateway_refund_id, 'Too early to say'))->toThrow(BillingRefused::class)
        ->and($refund->refresh()->status)->toBe(RefundStatus::Processing)
        ->and(fn () => askRefund())->toThrow(BillingRefused::class, 'still open');

    postWebhook(fakeGateway()->refundWebhook((string) $refund->gateway_refund_id))->assertOk();
    expect($refund->refresh()->status)->toBe(RefundStatus::Processed)->and(CreditNote::query()->count())->toBe(1);
});

it('★ no double refund: a request still in flight can’t be closed until it is overdue', function (): void {
    $refund = askRefund();
    $refund->forceFill(['status' => RefundStatus::Processing, 'decided_by_id' => $this->approver->id, 'decided_at' => now()])->save();   // crashed between commit and the gateway's answer
    $refunds = app(ManageRefund::class);

    expect(fn () => $refunds->closeAsNotSent($this->approver, $refund, 'Looks stuck'))->toThrow(BillingRefused::class);

    $this->travel(Refund::STUCK_HOURS + 1)->hours();
    expect($refunds->closeAsNotSent($this->approver, $refund->refresh(), 'Not in Razorpay')->status)->toBe(RefundStatus::Failed);
});

it('★ an accepted refund whose webhook was lost: closed after the wait, quoting its own id, as Razorpay shows it — paid, or failed (never freed while Razorpay shows it paid or pending); a late "created" doesn’t bring it back', function (): void {
    $refunds = app(ManageRefund::class);
    fakeGateway()->nextRefund('pending');
    $paid = $refunds->approve($this->approver, askRefund(10_000));
    $this->travel(Refund::STUCK_HOURS + 1)->hours();

    fakeGateway()->refundStatusAtGateway((string) $paid->gateway_refund_id, 'processed');   // paid there; its webhook was lost
    expect(fn () => $refunds->closeAsSent($this->approver, $paid, 'rfnd_typo12345', 'Seen in Razorpay'))->toThrow(BillingRefused::class, 'isn’t the refund id')
        ->and($paid->refresh()->gateway_refund_id)->not->toBe('rfnd_typo12345')
        ->and(fn () => $refunds->closeAsFailedAtGateway($this->approver, $paid, 'Looks failed'))->toThrow(BillingRefused::class, 'not failed')
        ->and($paid->refresh()->status)->toBe(RefundStatus::Processed);   // what Razorpay shows is recorded instead

    fakeGateway()->nextRefund('pending');
    $sent = $refunds->approve($this->approver, askRefund(10_000));
    $this->travel(Refund::STUCK_HOURS + 1)->hours();
    fakeGateway()->refundStatusAtGateway((string) $sent->gateway_refund_id, 'processed');
    expect($refunds->closeAsSent($this->approver, $sent, (string) $sent->gateway_refund_id, 'Seen in Razorpay')->status)->toBe(RefundStatus::Processed);

    fakeGateway()->nextRefund('pending');
    $failed = $refunds->approve($this->approver, askRefund(10_000));
    $this->travel(Refund::STUCK_HOURS + 1)->hours();
    expect(fn () => $refunds->closeAsFailedAtGateway($this->approver, $failed, 'Failed in Razorpay'))->toThrow(BillingRefused::class, 'not failed')
        ->and($failed->refresh()->status)->toBe(RefundStatus::Processing);   // Razorpay still shows it pending: nothing freed
    fakeGateway()->refundStatusAtGateway((string) $failed->gateway_refund_id, 'failed');
    expect($refunds->closeAsFailedAtGateway($this->approver, $failed, 'Failed in Razorpay')->status)->toBe(RefundStatus::Failed);

    postWebhook(fakeGateway()->refundWebhook((string) $failed->gateway_refund_id, 'refund.created'))->assertOk();
    expect($failed->refresh()->status)->toBe(RefundStatus::Failed);
});

it('closing as sent: an id another refund holds is refused, and nothing is recorded as closed', function (): void {
    $refunds = app(ManageRefund::class);
    $done = $refunds->approve($this->approver, askRefund(10_000));
    fakeGateway()->nextRefund('unavailable');
    $stuck = $refunds->approve($this->approver, askRefund(10_000));

    expect(fn () => $refunds->closeAsSent($this->approver, $stuck, (string) $done->gateway_refund_id, 'Copied the wrong id'))->toThrow(BillingRefused::class, 'belongs to refund '.$done->code)
        ->and($stuck->refresh()->status)->toBe(RefundStatus::Processing)
        ->and(AuditLog::query()->where('action', 'billing.refund_closed_by_hand')->count())->toBe(0)
        ->and(BillingInvestigation::query()->where('refund_id', $done->id)->count())->toBe(0);
});

it('a refund coming back after FAILED that completes the payment ends the plan', function (): void {
    $refunds = app(ManageRefund::class);
    $total = $this->payment->amount_paise;
    $share = intdiv($total * 6, 10);
    fakeGateway()->nextRefund('unavailable');
    $first = $refunds->approve($this->approver, askRefund($share));
    $this->travel(31)->minutes();
    $refunds->closeAsNotSent($this->approver, $first, 'Not in Razorpay yet');
    $refunds->approve($this->approver, askRefund($total - $share));
    expect(Subscription::query()->where('order_id', $this->order->id)->sole()->status)->toBe(SubscriptionStatus::Active);

    $late = fakeGateway()->refund($this->payment->gateway_payment_id, $share, $first->code);
    postWebhook(fakeGateway()->refundWebhook($late->id))->assertOk();

    expect($first->refresh()->ends_plan)->toBeTrue()
        ->and(Subscription::query()->where('order_id', $this->order->id)->sole()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($this->member->profile->refresh()->is_premium)->toBeFalse();
});

it('a refund coming back while another is only waiting or processing doesn’t end the plan: reserved money isn’t returned money', function (string $other): void {
    $refunds = app(ManageRefund::class);
    $total = $this->payment->amount_paise;
    $share = intdiv($total * 6, 10);
    fakeGateway()->nextRefund('unavailable');
    $first = $refunds->approve($this->approver, askRefund($share));
    $this->travel(31)->minutes();
    $refunds->closeAsNotSent($this->approver, $first, 'Not in Razorpay yet');
    $second = askRefund($total - $share);
    if ($other === 'failing') {
        fakeGateway()->nextRefund('pending');
        $second = $refunds->approve($this->approver, $second);
    }

    $late = fakeGateway()->refund($this->payment->gateway_payment_id, $share, $first->code);
    postWebhook(fakeGateway()->refundWebhook($late->id))->assertOk();

    if ($other === 'failing') {
        postWebhook(fakeGateway()->refundWebhook((string) $second->gateway_refund_id, 'refund.failed'))->assertOk();
    } else {
        $refunds->reject($this->approver, $second->refresh(), 'Outside the policy');
    }

    expect($first->refresh()->status)->toBe(RefundStatus::Processed)->and($first->ends_plan)->toBeFalse()
        ->and(Subscription::query()->where('order_id', $this->order->id)->sole()->status)->toBe(SubscriptionStatus::Active);

    // The rest goes back later: now it ends.
    $refunds->approve($this->approver, askRefund($total - $share));
    expect(Subscription::query()->where('order_id', $this->order->id)->sole()->status)->toBe(SubscriptionStatus::Cancelled);
})->with(['waiting for approval' => 'waiting', 'processing, then failing' => 'failing']);

it('a refund wrongly closed as not sent can be recorded as sent after all; one the gateway itself failed can’t', function (): void {
    $refunds = app(ManageRefund::class);
    fakeGateway()->nextRefund('unavailable');
    $wrong = $refunds->approve($this->approver, askRefund(10_000));
    $this->travel(31)->minutes();
    $refunds->closeAsNotSent($this->approver, $wrong, 'Not in Razorpay yet');
    $appeared = fakeGateway()->plantRefund($this->payment->gateway_payment_id, 10_000, $wrong->code);   // listed at Razorpay only later

    expect($refunds->closeAsSent($this->approver, $wrong->refresh(), $appeared->id, 'It appeared in Razorpay')->status)->toBe(RefundStatus::Processed)
        ->and(CreditNote::query()->where('refund_id', $wrong->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'billing.refund_closed_by_hand')->latest('created_at')->orderByDesc('id')->get()->first(fn (AuditLog $a): bool => ($a->after['outcome'] ?? null) === 'sent')?->before['status'])->toBe(RefundStatus::Failed->value);

    fakeGateway()->nextRefund('pending');
    $failed = $refunds->approve($this->approver, askRefund(10_000));
    postWebhook(fakeGateway()->refundWebhook((string) $failed->gateway_refund_id, 'refund.failed'))->assertOk();

    expect(fn () => $refunds->closeAsSent($this->approver, $failed->refresh(), (string) $failed->gateway_refund_id, 'Trying anyway'))->toThrow(BillingRefused::class);
});

it('force-activated after a refund was closed as not sent, then that refund turns out to have gone: the plan ends', function (): void {
    $over = checkoutOrder(bride(), PlanCode::Gold);
    capturePayment($over, $over->total_paise + 100);   // held back as a mismatch; enough to pay for the plan
    $payment = Payment::query()->where('order_id', $over->id)->sole();
    $refunds = app(ManageRefund::class);
    fakeGateway()->nextRefund('unavailable');
    $refund = $refunds->approve($this->approver, $refunds->request($this->requester, $over->refresh(), $payment->gateway_payment_id, RefundCategory::DuplicatePayment, $payment->amount_paise, 'Sending it back'));
    $this->travel(31)->minutes();
    $refunds->closeAsNotSent($this->approver, $refund, 'Not in Razorpay yet');
    app(ResolveStuckOrder::class)->forceActivate(adminWithRole('super_admin'), $over->refresh(), 'Member confirmed');
    expect(Subscription::query()->where('order_id', $over->id)->sole()->status)->toBe(SubscriptionStatus::Active);

    $late = fakeGateway()->refund($payment->gateway_payment_id, $payment->amount_paise, $refund->code);
    postWebhook(fakeGateway()->refundWebhook($late->id))->assertOk();

    expect(Subscription::query()->where('order_id', $over->id)->sole()->status)->toBe(SubscriptionStatus::Cancelled);
});

it('★ no double refund: a success reported after FAILED is believed with a row; the retry then can’t be approved past the balance', function (): void {
    $refunds = app(ManageRefund::class);
    fakeGateway()->nextRefund('unavailable');
    $first = $refunds->approve($this->approver, askRefund());
    $this->travel(31)->minutes();
    $refunds->closeAsNotSent($this->approver, $first, 'Not in Razorpay yet');
    $retry = askRefund();                                                       // the money looked free
    $late = fakeGateway()->refund($this->payment->gateway_payment_id, $first->amount_paise, $first->code);

    postWebhook(fakeGateway()->refundWebhook($late->id))->assertOk();

    expect($first->refresh()->status)->toBe(RefundStatus::Processed)
        ->and(BillingInvestigation::query()->where('refund_id', $first->id)->where('type', InvestigationType::RefundFailed)->sole()->details['problem'])->toBe('processed_after_failed')
        ->and(fn () => $refunds->approve($this->approver, $retry))->toThrow(BillingRefused::class)
        ->and($retry->refresh()->status)->toBe(RefundStatus::Requested)
        ->and(CreditNote::query()->count())->toBe(1);
});

it('refund.failed after acceptance: FAILED with a row, nothing applied, and the retry gives one correct credit note', function (): void {
    $referral = earnedCommission(Broker::factory()->create(), 60_000);
    $referral->forceFill(['order_id' => $this->order->id])->save();
    $refunds = app(ManageRefund::class);
    $invoice = Invoice::query()->where('order_id', $this->order->id)->sole();
    fakeGateway()->nextRefund('pending');
    $refund = $refunds->approve($this->approver, askRefund());

    postWebhook(fakeGateway()->refundWebhook((string) $refund->gateway_refund_id, 'refund.failed'))->assertOk();

    expect($refund->refresh()->status)->toBe(RefundStatus::Failed)
        ->and(BillingInvestigation::query()->sole()->type)->toBe(InvestigationType::RefundFailed)
        ->and(CreditNote::query()->count())->toBe(0)
        ->and(Subscription::query()->where('order_id', $this->order->id)->sole()->status)->toBe(SubscriptionStatus::Active)
        ->and($referral->refresh()->status)->toBe(ReferralStatus::Earned);

    $refunds->approve($this->approver, askRefund());
    $note = CreditNote::query()->sole();

    expect($note->total_paise)->toBe($invoice->total_paise)
        ->and($note->taxable_paise)->toBe($invoice->taxable_paise)
        ->and($note->cgst_paise)->toBe($invoice->cgst_paise)
        ->and($note->sgst_paise)->toBe($invoice->sgst_paise);
});

it('credit notes: every GST head of partial then rest adds up to the invoice exactly; each note’s heads add up to its total', function (): void {
    $refunds = app(ManageRefund::class);
    $invoice = Invoice::query()->where('order_id', $this->order->id)->sole();
    $refunds->approve($this->approver, askRefund(33_333));
    $refunds->approve($this->approver, askRefund(25_001));
    $refunds->approve($this->approver, askRefund($this->payment->amount_paise - 58_334));
    $notes = CreditNote::query()->get();

    foreach (['taxable_paise', 'cgst_paise', 'sgst_paise', 'igst_paise', 'total_paise'] as $head) {
        expect((int) $notes->sum($head))->toBe($invoice->{$head});
    }
    $notes->each(fn (CreditNote $n) => expect($n->taxable_paise + $n->cgst_paise + $n->sgst_paise + $n->igst_paise)->toBe($n->total_paise));
});

it('★ owner 2026-11-03: a short payment can’t be force-activated, so no invoice charges GST on money never received; it goes back in full with no credit note', function (): void {
    $short = checkoutOrder(bride(), PlanCode::Gold);
    capturePayment($short, 50_000);
    $payment = Payment::query()->where('order_id', $short->id)->sole();

    expect(fn () => app(ResolveStuckOrder::class)->forceActivate(adminWithRole('super_admin'), $short->refresh(), 'Member confirmed the short payment'))
        ->toThrow(BillingRefused::class, 'Only ₹500 of ₹')
        ->and($short->refresh()->status)->not->toBe(OrderStatus::Paid)
        ->and(Invoice::query()->where('order_id', $short->id)->count())->toBe(0)
        ->and(Subscription::query()->where('order_id', $short->id)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'billing.order_force_activated')->count())->toBe(0);

    $refund = app(ManageRefund::class)->request($this->requester, $short, $payment->gateway_payment_id, RefundCategory::Goodwill, 50_000, 'Going back in full');
    expect(app(ManageRefund::class)->approve($this->approver, $refund)->status)->toBe(RefundStatus::Processed)
        ->and(CreditNote::query()->where('refund_id', $refund->id)->count())->toBe(0);   // nothing was invoiced to credit
});

it('an extra payment going back leaves the valid order’s commission alone; the activating payment voids it', function (): void {
    $referral = earnedCommission(Broker::factory()->create(), 60_000);
    $referral->forceFill(['order_id' => $this->order->id])->save();
    $extra = fakeGateway()->capture((string) $this->order->gateway_order_id);
    app(HandlePaymentWebhook::class)->handle($extra['body'], $extra['signature'], $extra['event_id']);
    $payment = Payment::query()->where('gateway_payment_id', $extra['payment_id'])->sole();

    app(ManageRefund::class)->approve($this->approver, askRefund($payment->amount_paise, payment: $payment));
    expect($referral->refresh()->status)->toBe(ReferralStatus::Earned);

    app(ManageRefund::class)->approve($this->approver, askRefund());
    expect($referral->refresh()->status)->toBe(ReferralStatus::Void);
});

it('a refund made in the gateway’s dashboard raises a row and changes nothing else', function (): void {
    $unknown = fakeGateway()->refund($this->payment->gateway_payment_id, 500, 'not-ours');
    postWebhook(fakeGateway()->refundWebhook($unknown->id))->assertOk();

    $row = BillingInvestigation::query()->sole();
    expect($row->type)->toBe(InvestigationType::UnknownRefund)
        ->and($row->order_id)->toBe($this->order->id)
        ->and(Refund::query()->count())->toBe(0)
        ->and(Subscription::query()->where('order_id', $this->order->id)->sole()->status)->toBe(SubscriptionStatus::Active);
});

it('P5.3 Minor: a refund settled once, then revived by a late report and stuck again, gets its stuck row back; a row a person resolved stays resolved', function (): void {
    $refunds = app(ManageRefund::class);
    fakeGateway()->nextRefund('unavailable');
    $refund = $refunds->approve($this->approver, askRefund());
    $this->travel(31)->minutes();
    $refunds->closeAsNotSent($this->approver, $refund, 'Not in Razorpay yet');
    $stuckRow = fn () => BillingInvestigation::query()->where('refund_id', $refund->id)->where('type', InvestigationType::RefundStuck)->sole();
    expect($stuckRow()->status)->toBe(App\Enums\InvestigationStatus::Resolved);

    // It turns up at Razorpay after all, accepted but never confirmed.
    $late = fakeGateway()->plantRefund($this->payment->gateway_payment_id, $refund->amount_paise, $refund->code, 'pending');
    postWebhook(fakeGateway()->refundWebhook($late->id, 'refund.created'))->assertOk();
    expect($refund->refresh()->status)->toBe(RefundStatus::Processing);

    $this->travel(Refund::STUCK_HOURS + 1)->hours();
    (new App\Jobs\Billing\ReconcilePayments)->handle(app(App\Contracts\PaymentGateway::class), app(App\Actions\Billing\ActivateSubscription::class));
    expect($stuckRow()->status)->toBe(App\Enums\InvestigationStatus::Open)
        ->and($stuckRow()->resolved_at)->toBeNull();

    // Finance looks at it and resolves it: the next nightly run leaves it resolved.
    app(App\Actions\Admin\Billing\ResolveInvestigation::class)->handle($this->approver, $stuckRow(), 'Raised with Razorpay support');
    (new App\Jobs\Billing\ReconcilePayments)->handle(app(App\Contracts\PaymentGateway::class), app(App\Actions\Billing\ActivateSubscription::class));
    expect($stuckRow()->status)->toBe(App\Enums\InvestigationStatus::Resolved)
        ->and($stuckRow()->resolution_note)->toBe('Raised with Razorpay support');
});
