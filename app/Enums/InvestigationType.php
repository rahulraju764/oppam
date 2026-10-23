<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What the daily reconciliation (A06) or a refund failure found that a person must look at —
 * one "investigate" row each, resolved with a note. Payments the gateway captured for a known
 * order but we never heard about are not rows: the job activates them exactly as the webhook
 * would have, and only what that can't settle becomes a row.
 */
enum InvestigationType: string
{
    case UnknownOrder = 'UNKNOWN_ORDER';
    case AmountMismatch = 'AMOUNT_MISMATCH';
    case ExtraPayment = 'EXTRA_PAYMENT';
    case NotAtGateway = 'NOT_AT_GATEWAY';
    case RefundFailed = 'REFUND_FAILED';
    case RefundStuck = 'REFUND_STUCK';

    public function label(): string
    {
        return match ($this) {
            self::UnknownOrder => __('Payment for an order we don’t have'),
            self::AmountMismatch => __('Captured amount differs from the order'),
            self::ExtraPayment => __('Second payment on a paid order'),
            self::NotAtGateway => __('Recorded here, not captured at the gateway'),
            self::RefundFailed => __('Refund failed at the gateway'),
            self::RefundStuck => __('Refund not confirmed by the gateway'),
        };
    }

    /** What finance usually does next (shown on the row). */
    public function hint(): string
    {
        return match ($this) {
            self::UnknownOrder => __('Find the payment in the Razorpay dashboard; refund it there if nobody can be matched.'),
            self::AmountMismatch => __('Check with the member; force-activate (super admin) or refund the payment.'),
            self::ExtraPayment => __('Refund the extra payment (category: duplicate / extra payment).'),
            self::NotAtGateway => __('Compare with the Razorpay dashboard before taking any action.'),
            self::RefundFailed => __('The plan was already ended. Retry the refund or settle it with the member.'),
            self::RefundStuck => __('Look the refund up in the Razorpay dashboard and record the outcome.'),
        };
    }
}
