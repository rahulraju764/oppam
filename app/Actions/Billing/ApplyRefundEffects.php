<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\SubscriptionStatus;
use App\Events\Billing\EntitlementsChanged;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Profile;
use App\Models\Refund;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\Billing\RefundIssued;
use App\Queries\Billing\CurrentMembership;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * A06 "automatic side-effects" of a refund the gateway confirmed as PROCESSED, once only
 * (`effects_applied_at`), inside SettleRefund's transaction: the credit note when it refunds the
 * payment that activated the order, the plan ended when it returns the rest of that payment, and the
 * member told. The broker commission is voided by RefundProcessed's listener.
 */
final class ApplyRefundEffects
{
    public function __construct(
        private readonly IssueCreditNote $creditNotes,
        private readonly SyncMembershipFlags $flags,
        private readonly CurrentMembership $membership,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Refund $refund): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Refund side effects run inside the settling transaction.');
        }
        if ($refund->effects_applied_at !== null) {
            return;
        }

        $order = Order::query()->whereKey($refund->order_id)->lockForUpdate()->firstOrFail();
        $profile = Profile::withTrashed()->whereKey($refund->profile_id)->lockForUpdate()->firstOrFail();

        $invoice = $refund->payment_id === $order->paid_payment_id ? Invoice::query()->where('order_id', $order->id)->first() : null;
        $note = $invoice !== null ? $this->creditNotes->handle($refund, $invoice) : null;

        $ended = $refund->ends_plan ? $this->endPlan($order, $profile) : false;

        $refund->forceFill(['effects_applied_at' => now()])->save();
        $this->audit->record('billing.refund_effects_applied', $refund, after: [
            'credit_note' => $note?->number, 'plan_ended' => $ended,
        ], subjectLabel: $refund->code);

        $user = $profile->user_id === null ? null : User::query()->find($profile->user_id);
        if ($user !== null) {
            $amount = $refund->amount()->format();
            $code = $order->code;
            DB::afterCommit(fn () => $user->notify(new RefundIssued($code, $amount, $ended)));
        }
    }

    private function endPlan(Order $order, Profile $profile): bool
    {
        $now = CarbonImmutable::now();
        $subscription = Subscription::query()->where('order_id', $order->id)->where('status', SubscriptionStatus::Active)->lockForUpdate()->first();
        if ($subscription === null) {
            return false;
        }

        $subscription->forceFill(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => $now, 'ends_at' => $now])->save();
        $plan = $this->flags->handle($profile, $now);

        if ($profile->user_id !== null) {
            $until = $this->membership->current($profile, $now)?->ends_at;
            EntitlementsChanged::dispatch($profile->user_id, $plan->code->value, $plan->name,
                $until?->copy()->timezone((string) config('oppam.display_timezone'))->format('j M Y') ?? '');
        }

        return true;
    }
}
