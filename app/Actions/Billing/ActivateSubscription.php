<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Data\Billing\GatewayPaymentData;
use App\Domain\Billing\PlanChangeRule;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PlanChange;
use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Events\Billing\EntitlementsChanged;
use App\Events\Billing\OrderPaid;
use App\Jobs\Billing\GenerateInvoicePdf;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\Billing\PaymentSucceeded;
use App\Queries\Billing\CurrentMembership;
use App\Services\Entitlements\EntitlementService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * A captured payment activates its order (F04: "DB transaction: order PAID, payment row,
 * subscription ACTIVE, entitlement counters set, invoice"). Called only with gateway data from a
 * signature-verified webhook or a server-side gateway look-up — never from the browser.
 *
 * Idempotent: the order row is locked first, and a payment id already captured returns "duplicate"
 * — a replayed webhook, or `order.paid` after `payment.captured`, never activates twice. Refuses
 * to activate when the captured amount or currency differs from the order's (logged critical for
 * A06 "stuck orders"); a second captured payment on a PAID order is recorded and logged for refund.
 * After commit: EntitlementsChanged (the composer unlocks live), the payment_success notification,
 * the invoice PDF job and OrderPaid (broker commission, M13 / P7.8).
 */
final class ActivateSubscription
{
    public const ACTIVATED = 'activated';

    public const DUPLICATE = 'duplicate';

    public const UNKNOWN_ORDER = 'unknown_order';

    public const AMOUNT_MISMATCH = 'amount_mismatch';

    public const EXTRA_PAYMENT = 'extra_payment';

    public function __construct(
        private readonly CurrentMembership $membership,
        private readonly PlanChangeRule $planChange,
        private readonly IssueInvoice $invoices,
        private readonly EntitlementService $entitlements,
        private readonly SyncMembershipFlags $flags,
    ) {}

    public function handle(GatewayPaymentData $payment): string
    {
        return DB::transaction(function () use ($payment): string {
            $order = Order::query()->where('gateway_order_id', $payment->orderId)->lockForUpdate()->first();
            if ($order === null) {
                Log::warning('billing.unknown_gateway_order', ['gateway_order_id' => $payment->orderId, 'payment_id' => $payment->id]);

                return self::UNKNOWN_ORDER;
            }

            $row = Payment::query()->where('gateway_payment_id', $payment->id)->lockForUpdate()->first();
            if ($row?->status === PaymentStatus::Captured) {
                return self::DUPLICATE;
            }

            $row ??= new Payment;
            $row->forceFill([
                'order_id' => $order->id,
                'gateway_payment_id' => $payment->id,
                'amount_paise' => $payment->amountPaise,
                'currency' => $payment->currency,
                'method' => $payment->method,
                'status' => PaymentStatus::Captured,
                'error_code' => null,
                'captured_at' => now(),
            ])->save();

            if ($order->status === OrderStatus::Paid) {
                Log::critical('billing.extra_payment', ['order' => $order->code, 'payment_id' => $payment->id]);

                return self::EXTRA_PAYMENT;
            }

            if ($payment->amountPaise !== $order->total_paise || strtoupper($payment->currency) !== $order->currency || ! $order->status->isPayable()) {
                $order->forceFill(['failure_reason' => self::AMOUNT_MISMATCH])->save();
                Log::critical('billing.amount_mismatch', [
                    'order' => $order->code, 'payment_id' => $payment->id,
                    'expected' => $order->total_paise, 'captured' => $payment->amountPaise, 'currency' => $payment->currency,
                ]);

                return self::AMOUNT_MISMATCH;
            }

            $this->activate($order, $row);

            return self::ACTIVATED;
        });
    }

    /**
     * A06 force-activate (super admin, P5.3): activate an order whose captured payment was held
     * back (amount mismatch) after finance checked it. Same lock and same path as a webhook.
     * Call inside a transaction with the order row locked; $payment is captured and belongs to it.
     */
    public function activateWith(Order $order, Payment $payment): void
    {
        if (DB::transactionLevel() === 0 || $order->status === OrderStatus::Paid || $payment->order_id !== $order->id || $payment->status !== PaymentStatus::Captured) {
            throw new LogicException('Force-activation needs a locked unpaid order and its captured payment.');
        }

        $this->activate($order, $payment);
    }

    private function activate(Order $order, Payment $payment): void
    {
        $now = CarbonImmutable::now();
        $profile = Profile::withTrashed()->whereKey($order->profile_id)->lockForUpdate()->firstOrFail();
        $plan = $order->plan()->firstOrFail();

        $current = $this->membership->current($profile, $now);
        $change = $this->planChange->decide($current?->plan, $plan);
        [$startsAt, $endsAt] = $this->membership->period($profile, $plan->id, $change, $order->duration_months, $now);

        if ($change === PlanChange::Upgrade) {
            // No proration (decisions 2026-10-21): what is running or queued ends now.
            $this->membership->running($profile, $now)->update([
                'status' => SubscriptionStatus::Cancelled->value,
                'cancelled_at' => $now,
                'ends_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $subscription = new Subscription;
        $subscription->forceFill([
            'profile_id' => $profile->id,
            'plan_id' => $plan->id,
            'order_id' => $order->id,
            'status' => SubscriptionStatus::Active,
            'source' => SubscriptionSource::Paid,
            'duration_months' => $order->duration_months,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ])->save();

        $this->flags->handle($profile, $now);   // is_premium, highlighted_until, top_placement_until
        $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => $now, 'paid_payment_id' => $payment->id, 'failure_reason' => null])->save();

        if ($order->coupon_id !== null) {
            // Lock the coupon BEFORE inserting the redemption: the insert's foreign-key check takes a
            // shared lock on it, and upgrading that lock deadlocked two captures with the same coupon.
            $coupon = Coupon::query()->whereKey($order->coupon_id)->lockForUpdate()->first();
            $redemption = new CouponRedemption;
            $redemption->forceFill([
                'coupon_id' => $order->coupon_id,
                'profile_id' => $profile->id,
                'order_id' => $order->id,
                'discount_paise' => $order->discount_paise,
            ])->save();
            // Checked when the order was made; two open orders paid later can pass a limit. The member
            // paid, so it can't be refused here — logged for A06 to see.
            $memberUses = CouponRedemption::query()->where('coupon_id', $order->coupon_id)->where('profile_id', $profile->id)->count();
            if ($coupon !== null && (($coupon->max_redemptions !== null && $coupon->redemptions_count + 1 > $coupon->max_redemptions)
                || $memberUses > $coupon->per_member_limit)) {
                Log::warning('billing.coupon_over_limit', ['order' => $order->code, 'coupon' => $coupon->code]);
            }
            Coupon::query()->whereKey($order->coupon_id)->increment('redemptions_count');
        }

        $invoice = $this->invoices->handle($order, $profile, $now);
        $this->entitlements->forget($profile);

        $user = $profile->user_id === null ? null : User::query()->find($profile->user_id);
        if ($user !== null) {
            EntitlementsChanged::dispatch($user->id, $plan->code->value, $plan->name, $endsAt->timezone((string) config('oppam.display_timezone'))->format('j M Y'));
            DB::afterCommit(fn () => $user->notify(new PaymentSucceeded($order->code, $plan->name, $endsAt, $invoice->number)));
        }

        GenerateInvoicePdf::dispatch($invoice->id)->afterCommit();
        OrderPaid::dispatch($order->id);
    }
}
