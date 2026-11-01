<?php

declare(strict_types=1);

namespace App\Actions\Admin\Billing;

use App\Actions\Admin\Billing\Concerns\AuthorizesBilling;
use App\Actions\Billing\SettleRefund;
use App\Contracts\PaymentGateway;
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
 * the transaction; SettleRefund takes it from there. `ends_plan`: this refund returns the rest of
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

            $locked->forceFill(['status' => RefundStatus::Processing, 'decided_by_id' => $admin->id, 'decided_at' => now(), 'decision_note' => $note])->save();
            $this->audit->record('billing.refund_approved', $locked, ['status' => RefundStatus::Requested->value], ['status' => RefundStatus::Processing->value], actor: $admin, subjectLabel: $locked->code);

            return $locked;
        });

        try {
            $response = $this->gateway->refund($claimed->payment()->firstOrFail()->gateway_payment_id, $claimed->amount_paise, $claimed->code);
        } catch (PaymentGatewayUnavailable $e) {
            report($e);
            $this->settle->failed($claimed, SettleRefund::GATEWAY_UNAVAILABLE);

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
