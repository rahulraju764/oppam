<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Data\Billing\GatewayRefundData;
use App\Enums\InvestigationType;
use App\Enums\RefundStatus;
use App\Events\Billing\RefundProcessed;
use App\Models\BillingInvestigation;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;

/**
 * What the gateway says about a refund (its create response or a `refund.*` webhook, in either
 * order and any number of times), under the refund's row lock. Accepted → the one-time side effects
 * (ApplyRefundEffects). Processed → PROCESSED and RefundProcessed after commit (the broker commission
 * is voided there). Failed → FAILED with an "investigate" row: effects already applied stay for
 * finance to judge. A report that doesn't match the refund changes nothing and raises a row.
 */
final class SettleRefund
{
    public const SETTLED = 'refund_settled';

    public const UNKNOWN = 'refund_unknown';

    public const GATEWAY_UNAVAILABLE = 'gateway_unavailable';

    public const GATEWAY_FAILED = 'gateway_failed';

    public function __construct(private readonly ApplyRefundEffects $effects) {}

    public function handle(GatewayRefundData $data): string
    {
        return DB::transaction(function () use ($data): string {
            $refund = Refund::query()
                ->where(fn ($q) => $q->where('gateway_refund_id', $data->id)
                    ->when($data->refundCode !== null, fn ($x) => $x->orWhere('code', $data->refundCode)))
                ->lockForUpdate()->first();

            if ($refund === null) {
                return self::UNKNOWN;   // made at the gateway directly: the daily reconciliation reports payments, not refunds
            }

            $gatewayPayment = Payment::query()->whereKey($refund->payment_id)->value('gateway_payment_id');
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

    /** The gateway couldn't be asked: nothing went back as far as we know; finance checks. */
    public function failed(Refund $refund, string $reason): void
    {
        DB::transaction(function () use ($refund, $reason): void {
            $locked = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === RefundStatus::Processing) {
                $this->markFailed($locked, $reason);
            }
        });
    }

    private function accept(Refund $refund): void
    {
        if ($refund->status === RefundStatus::Processing) {
            $this->effects->handle($refund);
        }
        $refund->save();
    }

    private function markProcessed(Refund $refund): void
    {
        if ($refund->status === RefundStatus::Processed) {
            return;   // a redelivery
        }

        $this->effects->handle($refund);
        $refund->forceFill(['status' => RefundStatus::Processed, 'processed_at' => now(), 'failure_reason' => null])->save();
        RefundProcessed::dispatch($refund->id);
    }

    private function markFailed(Refund $refund, string $reason): void
    {
        if ($refund->status === RefundStatus::Processed) {
            $this->raise($refund, ['problem' => 'failed_after_processed', 'gateway_refund_id' => $refund->gateway_refund_id]);

            return;
        }

        $refund->forceFill(['status' => RefundStatus::Failed, 'failure_reason' => $reason])->save();
        $this->raise($refund, ['problem' => $reason, 'effects_applied' => $refund->effects_applied_at !== null ? 'yes' : 'no']);
    }

    /** @param  array<string, int|string|null>  $details */
    private function raise(Refund $refund, array $details): void
    {
        BillingInvestigation::raise(InvestigationType::RefundFailed, $refund->code.':'.($details['problem'] ?? ''), ['refund' => $refund->code, 'amount_paise' => $refund->amount_paise, ...$details], $refund->order_id, $refund->id);
    }
}
