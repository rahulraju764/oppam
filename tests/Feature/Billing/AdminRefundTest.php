<?php

declare(strict_types=1);

use App\Actions\Admin\Billing\ManageRefund;
use App\Actions\Billing\HandlePaymentWebhook;
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

    // Gateway accepted and processed.
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

it('gateway accepts, then the webhook confirms: effects once on acceptance; commission only on PROCESSED; a redelivery changes nothing', function (): void {
    $referral = earnedCommission(Broker::factory()->create(), 60_000);
    $referral->forceFill(['order_id' => $this->order->id])->save();
    fakeGateway()->nextRefund('pending');

    $refund = app(ManageRefund::class)->approve($this->approver, askRefund());

    expect($refund->status)->toBe(RefundStatus::Processing)
        ->and($refund->effects_applied_at)->not->toBeNull()
        ->and(CreditNote::query()->count())->toBe(1)
        ->and($referral->refresh()->status)->toBe(ReferralStatus::Earned);

    $delivery = fakeGateway()->refundWebhook((string) $refund->gateway_refund_id);
    postWebhook($delivery)->assertOk();
    postWebhook(['event_id' => 'evt_second'] + $delivery)->assertOk();

    expect($refund->refresh()->status)->toBe(RefundStatus::Processed)
        ->and(CreditNote::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'billing.refund_effects_applied')->count())->toBe(1)
        ->and($referral->refresh()->status)->toBe(ReferralStatus::Void);
    Notification::assertSentToTimes($this->member, RefundIssued::class, 1);
});

it('the gateway can’t be reached: FAILED with an investigate row, nothing applied, and it can be requested again', function (): void {
    fakeGateway()->nextRefund('unavailable');

    $refund = app(ManageRefund::class)->approve($this->approver, askRefund());

    expect($refund->status)->toBe(RefundStatus::Failed)
        ->and($refund->effects_applied_at)->toBeNull()
        ->and(CreditNote::query()->count())->toBe(0)
        ->and(Subscription::query()->where('order_id', $this->order->id)->sole()->status)->toBe(SubscriptionStatus::Active)
        ->and(BillingInvestigation::query()->sole()->type)->toBe(InvestigationType::RefundFailed);

    expect(askRefund()->status)->toBe(RefundStatus::Requested);
});

it('refund.failed after acceptance: FAILED, investigate row, the commission stays', function (): void {
    $referral = earnedCommission(Broker::factory()->create(), 60_000);
    $referral->forceFill(['order_id' => $this->order->id])->save();
    fakeGateway()->nextRefund('pending');
    $refund = app(ManageRefund::class)->approve($this->approver, askRefund());

    postWebhook(fakeGateway()->refundWebhook((string) $refund->gateway_refund_id, 'refund.failed'))->assertOk();

    expect($refund->refresh()->status)->toBe(RefundStatus::Failed)
        ->and(BillingInvestigation::query()->sole()->details['effects_applied'])->toBe('yes')
        ->and($referral->refresh()->status)->toBe(ReferralStatus::Earned);
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
