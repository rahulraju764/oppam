<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Data\Billing\GatewayRefundData;
use App\Domain\Billing\RefundBalance;
use App\Enums\InvestigationStatus;
use App\Enums\InvestigationType;
use App\Enums\RefundStatus;
use App\Events\Billing\RefundProcessed;
use App\Models\BillingInvestigation;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;

/**
 * What the gateway says about a refund (its create response or a `refund.*` webhook, in either
 * order and any number of times), under the refund's row lock. The money is only known to be back
 * at PROCESSED, so that is when the side effects run (ApplyRefundEffects) and RefundProcessed fires.
 * Failed → FAILED with an "investigate" row. A call that got no answer stays PROCESSING and keeps the
 * money reserved: it may have gone through (`unknownOutcome`), and only a person can close it
 * (ManageRefund's close actions). A success reported on a FAILED refund is believed, with a row, since
 * money moved. A report that doesn't match the refund changes nothing and raises a row; a refund we
 * have no record of (made in the gateway's dashboard) raises one too.
 */
final class SettleRefund
{
    public const SETTLED = 'refund_settled';

    public const UNKNOWN = 'refund_unknown';

    public const GATEWAY_UNANSWERED = 'gateway_unanswered';

    public const GATEWAY_FAILED = 'gateway_failed';

    public const CONFIRMED_NOT_SENT = 'confirmed_not_sent';

    public function __construct(
        private readonly ApplyRefundEffects $effects,
        private readonly RefundBalance $balance,
    ) {}

    public function handle(GatewayRefundData $data): string
    {
        return DB::transaction(function () use ($data): string {
            $refund = Refund::query()
                ->where(fn ($q) => $q->where('gateway_refund_id', $data->id)
                    ->when($data->refundCode !== null, fn ($x) => $x->orWhere('code', $data->refundCode)))
                ->lockForUpdate()->first();

            if ($refund === null) {
                $this->raiseUnknown($data);

                return self::UNKNOWN;
            }

            // Locks first, then reads: two refunds of one order settle one after the other, each seeing the other's note.
            Order::query()->whereKey($refund->order_id)->lockForUpdate()->firstOrFail();
            $gatewayPayment = Payment::query()->whereKey($refund->payment_id)->lockForUpdate()->value('gateway_payment_id');
            if ($gatewayPayment !== $data->paymentId || $refund->amount_paise !== $data->amountPaise
                || ! in_array($refund->status, [RefundStatus::Processing, RefundStatus::Processed, RefundStatus::Failed], true)) {
                $this->raise($refund, ['problem' => 'mismatch', 'gateway_refund_id' => $data->id, 'gateway_amount_paise' => $data->amountPaise]);

                return self::SETTLED;
            }

            $refund->forceFill(['gateway_refund_id' => $data->id]);

            match ($data->status) {
                RefundStatus::Failed => $this->markFailed($refund, self::GATEWAY_FAILED),
                RefundStatus::Processed => $this->markProcessed($refund),
                default => $this->accept($refund),
            };

            return self::SETTLED;
        });
    }

    /** No answer from the gateway: it may have gone through, so it stays reserved until someone checks. */
    public function unknownOutcome(Refund $refund): void
    {
        DB::transaction(function () use ($refund): void {
            $locked = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === RefundStatus::Processing && $locked->gateway_refund_id === null) {
                $locked->forceFill(['failure_reason' => self::GATEWAY_UNANSWERED])->save();
                BillingInvestigation::raise(InvestigationType::RefundStuck, $locked->code, ['refund' => $locked->code, 'amount_paise' => $locked->amount_paise, 'problem' => self::GATEWAY_UNANSWERED], $locked->order_id, $locked->id);
            }
        });
    }

    /** Someone checked the gateway's dashboard: nothing was sent, so the money is free to refund again. */
    public function confirmedNotSent(Refund $locked): void
    {
        $locked->forceFill(['status' => RefundStatus::Failed, 'failure_reason' => self::CONFIRMED_NOT_SENT])->save();
        $this->closeStuckRow($locked);
    }

    /** Someone checked the gateway's dashboard: an accepted refund failed there. */
    public function confirmedFailed(Refund $locked): void
    {
        $locked->forceFill(['status' => RefundStatus::Failed, 'failure_reason' => self::GATEWAY_FAILED])->save();
        $this->closeStuckRow($locked);
    }

    private function accept(Refund $refund): void
    {
        if ($refund->status === RefundStatus::Failed) {
            if ($refund->failure_reason === self::GATEWAY_FAILED) {
                return;   // a late "created" can't undo the gateway's own failure
            }
            $this->raise($refund, ['problem' => 'accepted_after_failed', 'gateway_refund_id' => $refund->gateway_refund_id]);
            $refund->forceFill(['status' => RefundStatus::Processing]);
        }
        $refund->forceFill(['failure_reason' => $refund->status === RefundStatus::Processing ? null : $refund->failure_reason])->save();
    }

    private function markProcessed(Refund $refund): void
    {
        if ($refund->status === RefundStatus::Processed) {
            return;   // a redelivery
        }
        if ($refund->status === RefundStatus::Failed) {
            $this->raise($refund, ['problem' => 'processed_after_failed', 'gateway_refund_id' => $refund->gateway_refund_id]);
        }

        // Decided now, when the money is known back: only what has really returned counts.
        $order = Order::query()->whereKey($refund->order_id)->firstOrFail();
        $payment = Payment::query()->whereKey($refund->payment_id)->firstOrFail();

        $refund->forceFill([
            'status' => RefundStatus::Processed, 'processed_at' => now(), 'failure_reason' => null,
            'ends_plan' => $payment->id === $order->paid_payment_id && $this->balance->returned($payment, $refund) + $refund->amount_paise >= $payment->amount_paise,
        ]);
        $this->effects->handle($refund);
        $refund->save();
        $this->closeStuckRow($refund);
        RefundProcessed::dispatch($refund->id);
    }

    private function markFailed(Refund $refund, string $reason): void
    {
        if ($refund->status === RefundStatus::Processed) {
            $this->raise($refund, ['problem' => 'failed_after_processed', 'gateway_refund_id' => $refund->gateway_refund_id]);

            return;
        }

        $refund->forceFill(['status' => RefundStatus::Failed, 'failure_reason' => $reason])->save();
        $this->closeStuckRow($refund);
        $this->raise($refund, ['problem' => $reason]);
    }

    /** The refund's outcome is known now: its "not confirmed" row has nothing left to ask. */
    private function closeStuckRow(Refund $refund): void
    {
        BillingInvestigation::query()->open()->where('refund_id', $refund->id)->where('type', InvestigationType::RefundStuck)
            ->update(['status' => InvestigationStatus::Resolved->value, 'resolved_at' => now(), 'resolution_note' => 'Settled: '.$refund->status->value]);
    }

    /** @param  array<string, int|string|null>  $details */
    private function raise(Refund $refund, array $details): void
    {
        BillingInvestigation::raise(InvestigationType::RefundFailed, $refund->code.':'.($details['problem'] ?? ''), ['refund' => $refund->code, 'amount_paise' => $refund->amount_paise, ...$details], $refund->order_id, $refund->id);
    }

    private function raiseUnknown(GatewayRefundData $data): void
    {
        $payment = Payment::query()->where('gateway_payment_id', $data->paymentId)->first();
        BillingInvestigation::raise(InvestigationType::UnknownRefund, $data->id, [
            'amount_paise' => $data->amountPaise, 'gateway_refund_id' => $data->id,
            'order' => $payment === null ? null : Order::query()->whereKey($payment->order_id)->value('code'),
        ], $payment?->order_id, null, $data->paymentId);
    }
}
