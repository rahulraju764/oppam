<?php

declare(strict_types=1);

namespace App\Actions\Admin\Billing;

use App\Actions\Admin\Billing\Concerns\AuthorizesBilling;
use App\Actions\Billing\SettleRefund;
use App\Contracts\PaymentGateway;
use App\Data\Billing\GatewayRefundData;
use App\Domain\Billing\RefundBalance;
use App\Enums\PaymentStatus;
use App\Enums\RefundCategory;
use App\Enums\RefundStatus;
use App\Exceptions\Admin\BillingRefused;
use App\Exceptions\Billing\PaymentGatewayUnavailable;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\Audit\AuditLogger;
use App\Services\Sequences\SequenceAllocator;
use App\ValueObjects\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A06 refunds, one order at a time, by category (`billing.refund`, audited). One admin requests,
 * ANOTHER approves or rejects (owner, every refund: decisions 2026-11-01). Approval moves it to
 * PROCESSING under the row lock, so two approvers can't both send it, then calls the gateway outside
 * the transaction; SettleRefund takes it from there. No answer from the gateway keeps the money
 * reserved until someone closes it (closeAsSent / closeAsNotSent): a timeout may have gone through. `ends_plan`: this refund returns the rest of
 * the payment that activated the order.
 */
final class ManageRefund
{
    use AuthorizesBilling;

    public function __construct(
        private readonly RefundBalance $balance,
        private readonly PaymentGateway $gateway,
        private readonly SettleRefund $settle,
        private readonly SequenceAllocator $sequences,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws BillingRefused not captured, another refund open, more than is left
     */
    public function request(AdminUser $admin, Order $order, string $gatewayPaymentId, RefundCategory $category, int $amountPaise, string $reason): Refund
    {
        $this->authorizeFor($admin, 'billing.refund');
        $reason = $this->validatedReason($reason);
        if ($amountPaise < 100) {
            throw ValidationException::withMessages(['amount' => __('Enter at least ₹1.')]);
        }

        return DB::transaction(function () use ($admin, $order, $gatewayPaymentId, $category, $amountPaise, $reason): Refund {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $open = Refund::query()->where('order_id', $locked->id)->whereIn('status', [RefundStatus::Requested->value, RefundStatus::Processing->value])->first();
            if ($open !== null) {
                throw BillingRefused::refundOpen($open->code);
            }

            $payment = Payment::query()->where('order_id', $locked->id)->where('gateway_payment_id', $gatewayPaymentId)->lockForUpdate()->firstOrFail();
            if ($payment->status !== PaymentStatus::Captured) {
                throw BillingRefused::notRefundable();
            }

            $left = $this->balance->left($payment);
            if ($amountPaise > $left) {
                throw BillingRefused::amountTooHigh(Money::paise($left)->format());
            }

            $refund = new Refund;
            $refund->forceFill([
                'code' => 'RFD'.$this->sequences->next('refund_code', 100001),
                'order_id' => $locked->id,
                'payment_id' => $payment->id,
                'profile_id' => $locked->profile_id,
                'category' => $category,
                'amount_paise' => $amountPaise,
                'ends_plan' => $payment->id === $locked->paid_payment_id && $amountPaise === $left,
                'reason' => $reason,
                'status' => RefundStatus::Requested,
                'requested_by_id' => $admin->id,
            ])->save();

            $this->audit->record('billing.refund_requested', $refund, after: [
                'order' => $locked->code, 'amount_paise' => $amountPaise, 'category' => $category->value, 'ends_plan' => $refund->ends_plan,
            ], reason: $reason, actor: $admin, subjectLabel: $refund->code);

            return $refund;
        });
    }

    /**
     * @throws AuthorizationException
     * @throws BillingRefused decided already, or the requester's own
     */
    public function approve(AdminUser $admin, Refund $refund, ?string $note = null): Refund
    {
        $this->authorizeFor($admin, 'billing.refund');

        $claimed = DB::transaction(function () use ($admin, $refund, $note): Refund {
            $locked = $this->waiting($refund);
            if ($locked->requested_by_id === $admin->id) {
                throw BillingRefused::ownRefund();
            }

            // The balance may have changed since the request (a late report of an earlier refund).
            $order = Order::query()->whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::query()->whereKey($locked->payment_id)->lockForUpdate()->firstOrFail();
            $left = $this->balance->left($payment, $locked);
            if ($locked->amount_paise > $left) {
                throw BillingRefused::amountTooHigh(Money::paise($left)->format());
            }

            $locked->forceFill([
                'status' => RefundStatus::Processing, 'decided_by_id' => $admin->id, 'decided_at' => now(), 'decision_note' => $note,
                'ends_plan' => $payment->id === $order->paid_payment_id && $locked->amount_paise === $left,
            ])->save();
            $this->audit->record('billing.refund_approved', $locked, ['status' => RefundStatus::Requested->value], ['status' => RefundStatus::Processing->value], actor: $admin, subjectLabel: $locked->code);

            return $locked;
        });

        try {
            $response = $this->gateway->refund($claimed->payment()->firstOrFail()->gateway_payment_id, $claimed->amount_paise, $claimed->code);
        } catch (PaymentGatewayUnavailable $e) {
            report($e);
            $this->settle->unknownOutcome($claimed);   // it may have gone through: the money stays reserved

            return $claimed->refresh();
        }

        $this->settle->handle($response);

        return $claimed->refresh();
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws BillingRefused decided already, or the requester's own (they withdraw it)
     */
    public function reject(AdminUser $admin, Refund $refund, string $note): Refund
    {
        $this->authorizeFor($admin, 'billing.refund');
        $note = $this->validatedReason($note, 'note');

        return DB::transaction(function () use ($admin, $refund, $note): Refund {
            $locked = $this->waiting($refund);
            if ($locked->requested_by_id === $admin->id) {
                throw BillingRefused::ownRefund();
            }

            $locked->forceFill(['status' => RefundStatus::Rejected, 'decided_by_id' => $admin->id, 'decided_at' => now(), 'decision_note' => $note])->save();
            $this->audit->record('billing.refund_rejected', $locked, ['status' => RefundStatus::Requested->value], ['status' => RefundStatus::Rejected->value], reason: $note, actor: $admin, subjectLabel: $locked->code);

            return $locked;
        });
    }

    /**
     * @throws AuthorizationException
     * @throws BillingRefused decided already, or someone else's
     */
    public function withdraw(AdminUser $admin, Refund $refund): Refund
    {
        $this->authorizeFor($admin, 'billing.refund');

        return DB::transaction(function () use ($admin, $refund): Refund {
            $locked = $this->waiting($refund);
            if ($locked->requested_by_id !== $admin->id) {
                throw BillingRefused::notYourRefund();
            }

            $locked->forceFill(['status' => RefundStatus::Cancelled])->save();
            $this->audit->record('billing.refund_withdrawn', $locked, ['status' => RefundStatus::Requested->value], ['status' => RefundStatus::Cancelled->value], actor: $admin, subjectLabel: $locked->code);

            return $locked;
        });
    }

    /**
     * Stuck refund (or one wrongly closed as not sent), seen in the gateway's dashboard as paid: settled
     * through the same path as a webhook.
     * An unanswered one takes the id from the dashboard, which no other refund may hold; an accepted
     * one must quote the id it already has. Reason, audited.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws BillingRefused it isn't stuck, or the id belongs to something else
     */
    public function closeAsSent(AdminUser $admin, Refund $refund, string $gatewayRefundId, string $reason): Refund
    {
        $this->authorizeFor($admin, 'billing.refund');
        $reason = $this->validatedReason($reason);
        $gatewayRefundId = trim($gatewayRefundId);
        if (preg_match('/^rfnd_[A-Za-z0-9]{4,40}$/', $gatewayRefundId) !== 1) {
            throw ValidationException::withMessages(['gatewayRefundId' => __('Enter the refund id from the Razorpay dashboard (rfnd_…).')]);
        }

        return DB::transaction(function () use ($admin, $refund, $gatewayRefundId, $reason): Refund {
            $locked = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isUnanswered() && ! $locked->isAcceptedAndOverdue() && ! $locked->isClosedAsNotSent()) {
                throw BillingRefused::refundNotStuck();
            }
            // Same lock order as SettleRefund, before any plain read: another settle on this order goes first or after.
            Order::query()->whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
            $earlier = $locked->status;
            if ($locked->gateway_refund_id !== null && $locked->gateway_refund_id !== $gatewayRefundId) {
                throw BillingRefused::refundIdDiffers();
            }
            if (Refund::query()->where('gateway_refund_id', $gatewayRefundId)->whereKeyNot($locked->id)->exists()) {
                throw BillingRefused::refundIdInUse();
            }

            $this->settle->handle(new GatewayRefundData($gatewayRefundId, $locked->payment()->firstOrFail()->gateway_payment_id, $locked->amount_paise, RefundStatus::Processed, $locked->code));
            $locked->refresh();
            if ($locked->status !== RefundStatus::Processed) {
                throw BillingRefused::refundNotStuck();   // rolled back: nothing is recorded as closed
            }

            return $this->recordClosed($admin, $locked, $earlier, 'sent', $reason);
        });
    }

    /**
     * The gateway never answered, and the dashboard shows nothing went: the money is free to refund
     * again. Refused once the gateway has accepted it. Reason, audited.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws BillingRefused it isn't an unanswered refund
     */
    public function closeAsNotSent(AdminUser $admin, Refund $refund, string $reason): Refund
    {
        $this->authorizeFor($admin, 'billing.refund');
        $reason = $this->validatedReason($reason);

        return DB::transaction(function () use ($admin, $refund, $reason): Refund {
            $locked = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isUnanswered()) {
                throw BillingRefused::refundNotStuck();
            }

            $this->settle->confirmedNotSent($locked);

            return $this->recordClosed($admin, $locked, RefundStatus::Processing, 'not_sent', $reason);
        });
    }

    /**
     * Accepted by the gateway, no webhook since, and the dashboard shows it failed. Only after
     * Refund::STUCK_HOURS, so a confirmation on its way can't be overtaken. Reason, audited.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws BillingRefused it isn't accepted and overdue
     */
    public function closeAsFailedAtGateway(AdminUser $admin, Refund $refund, string $reason): Refund
    {
        $this->authorizeFor($admin, 'billing.refund');
        $reason = $this->validatedReason($reason);

        return DB::transaction(function () use ($admin, $refund, $reason): Refund {
            $locked = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isAcceptedAndOverdue()) {
                throw BillingRefused::refundNotStuck();
            }

            $this->settle->confirmedFailed($locked);

            return $this->recordClosed($admin, $locked, RefundStatus::Processing, 'failed_at_gateway', $reason);
        });
    }

    private function recordClosed(AdminUser $admin, Refund $locked, RefundStatus $earlier, string $outcome, string $reason): Refund
    {
        $this->audit->record('billing.refund_closed_by_hand', $locked, ['status' => $earlier->value], ['status' => $locked->status->value, 'outcome' => $outcome], reason: $reason, actor: $admin, subjectLabel: $locked->code);

        return $locked;
    }

    /** @throws BillingRefused */
    private function waiting(Refund $refund): Refund
    {
        $locked = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== RefundStatus::Requested) {
            throw BillingRefused::refundNotWaiting();
        }

        return $locked;
    }
}
