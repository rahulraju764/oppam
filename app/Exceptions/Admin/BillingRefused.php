<?php

declare(strict_types=1);

namespace App\Exceptions\Admin;

use RuntimeException;

/** An A06 step doesn't fit the record's current state (shown to the admin as is). */
final class BillingRefused extends RuntimeException
{
    public static function planInUse(): self
    {
        return new self(__('This plan has orders or subscriptions, so it can’t be deleted. Deactivate it instead.'));
    }

    public static function freePlan(): self
    {
        return new self(__('The Free plan can’t be deactivated or deleted.'));
    }

    public static function couponUsed(): self
    {
        return new self(__('This coupon has been used, so it can’t be deleted. Deactivate it instead.'));
    }

    public static function orderAlreadyPaid(): self
    {
        return new self(__('This order is already paid.'));
    }

    public static function orderHoldsMoney(): self
    {
        return new self(__('This order has a captured payment. Refund it or force-activate it instead of marking it failed.'));
    }

    public static function noCapturedPayment(): self
    {
        return new self(__('This order has no captured payment to activate it with.'));
    }

    public static function superAdminOnly(): self
    {
        return new self(__('Only a super admin can force-activate an order.'));
    }

    public static function gatewayUnavailable(): self
    {
        return new self(__('The payment gateway can’t be reached right now. Try again shortly.'));
    }

    public static function refundOpen(string $code): self
    {
        return new self(__('Refund :code is still open on this order. Finish it first.', ['code' => $code]));
    }

    public static function notRefundable(): self
    {
        return new self(__('Only a captured payment can be refunded.'));
    }

    public static function amountTooHigh(string $left): self
    {
        return new self(__('Only ₹:amount of this payment can still be refunded.', ['amount' => $left]));
    }

    public static function refundNotWaiting(): self
    {
        return new self(__('This refund has already been decided.'));
    }

    public static function refundNotStuck(): self
    {
        return new self(__('This refund can’t be closed that way. The gateway may still report on it.'));
    }

    public static function refundIdDiffers(): self
    {
        return new self(__('That isn’t the refund id the gateway gave for this refund.'));
    }

    public static function refundIdInUse(): self
    {
        return new self(__('Another refund already has that id.'));
    }

    public static function refundTooEarlyToClose(string $from): self
    {
        return new self(__('Wait until :time before recording this as not sent: a refund still on its way may yet appear at Razorpay.', ['time' => $from]));
    }

    public static function refundFoundAtGateway(string $status): self
    {
        return new self(__('Razorpay has this refund (:status), so it was sent. That is now recorded here; it can’t be closed as not sent.', ['status' => $status]));
    }

    public static function refundNotAtGateway(string $id): self
    {
        return new self(__('Razorpay has no refund :id on this payment.', ['id' => $id]));
    }

    public static function refundBelongsToAnother(string $code): self
    {
        return new self(__('That Razorpay refund belongs to refund :code.', ['code' => $code]));
    }

    public static function refundAmountDiffers(string $amount): self
    {
        return new self(__('That Razorpay refund is for ₹:amount, not this refund’s amount.', ['amount' => $amount]));
    }

    public static function refundNotPaidAtGateway(string $status): self
    {
        return new self(__('Razorpay shows that refund as :status, not paid. It can be recorded as refunded once Razorpay shows it paid.', ['status' => $status]));
    }

    public static function refundNotFailedAtGateway(string $status): self
    {
        return new self(__('Razorpay shows this refund as :status, not failed. That is now recorded here.', ['status' => $status]));
    }

    public static function shortPayment(string $captured, string $total): self
    {
        return new self(__('Only ₹:captured of ₹:total was captured, so it can’t pay for this plan. Refund it; the member can pay again, or give a complimentary grant.', ['captured' => $captured, 'total' => $total]));
    }

    public static function paymentRefunded(): self
    {
        return new self(__('Part of this payment has been refunded or is being refunded, so it can’t activate the order.'));
    }

    public static function ownRefund(): self
    {
        return new self(__('A refund must be decided by a different admin from the one who requested it.'));
    }

    public static function notYourRefund(): self
    {
        return new self(__('Only the admin who requested a refund can withdraw it.'));
    }

    public static function investigationClosed(): self
    {
        return new self(__('This item has already been resolved.'));
    }
}
