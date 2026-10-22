<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Contracts\PaymentGateway;
use App\Enums\OrderStatus;
use App\Enums\PlanChange;
use App\Enums\PlanCode;
use App\Enums\ProfileStatus;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Billing\CheckoutRefused;
use App\Exceptions\Billing\PaymentGatewayUnavailable;
use App\Models\Order;
use App\Models\User;
use App\Services\Admin\Impersonation;
use App\Services\Sequences\SequenceAllocator;
use App\Support\Facades\Settings;
use Illuminate\Cache\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Start paying for a plan (M10, F04 "server creates order (amount from plan key + coupon + GST) →
 * Razorpay order"). The amount is whatever QuoteCheckout computes now from the plan KEY, duration
 * and coupon — the browser sends nothing else, so a tampered amount can't exist. Refused in an
 * admin support session (A01: payments), for a suspended / banned / deleted account, for a lower
 * plan while a higher one runs, and past `billing.checkouts_max_per_hour`.
 * Idempotent for a double click: an open order on exactly the same terms is returned again.
 */
final class CreateOrder
{
    /** An open order is reused only while this much of its life is left. */
    private const REUSE_MARGIN_MINUTES = 5;

    public function __construct(
        private readonly QuoteCheckout $quote,
        private readonly PaymentGateway $gateway,
        private readonly SequenceAllocator $sequences,
        private readonly Impersonation $impersonation,
        private readonly RateLimiter $limiter,
    ) {}

    /**
     * @throws ImpersonationRestricted
     * @throws CheckoutRefused
     * @throws ValidationException the coupon can't be used
     * @throws NotFoundHttpException the plan / duration isn't on sale
     */
    public function handle(User $member, PlanCode $code, int $duration, ?string $couponCode = null): Order
    {
        $this->impersonation->assertAllowed();

        $profile = $member->profile ?? throw new NotFoundHttpException;
        if ($member->status !== UserStatus::Active || $member->suspended_at !== null
            || in_array($profile->status, [ProfileStatus::Suspended, ProfileStatus::Deleted], true)) {
            throw CheckoutRefused::accountNotActive();
        }

        if (! $this->gateway->isConfigured()) {
            throw CheckoutRefused::unavailable();
        }

        $quote = $this->quote->handle($member, $code, $duration, $couponCode);
        if ($quote->change === PlanChange::Downgrade) {
            throw CheckoutRefused::downgrade(
                (string) $quote->currentPlanName,
                (string) $quote->currentEndsAt?->timezone((string) config('oppam.display_timezone'))->format('j M Y'),
            );
        }

        $open = Order::query()
            ->where('profile_id', $profile->id)
            ->where('status', OrderStatus::Created->value)
            ->where('plan_price_id', $quote->planPriceId)
            ->where('coupon_id', $quote->couponId)
            ->where('total_paise', $quote->price->totalPaise)
            ->where('gateway', $this->gateway->name())
            ->whereNotNull('gateway_order_id')
            ->where('expires_at', '>', now()->addMinutes(self::REUSE_MARGIN_MINUTES))
            ->latest()
            ->first();
        if ($open !== null) {
            return $open;
        }

        if ($this->limiter->hit('checkout-orders:'.$member->id, 3600) > Settings::int(SettingKey::BillingCheckoutsMaxPerHour)) {
            throw CheckoutRefused::tooMany();
        }

        $order = new Order;
        $order->forceFill([
            'code' => 'ORD'.$this->sequences->next('order_code', 100001),
            'profile_id' => $profile->id,
            'plan_id' => $quote->planId,
            'plan_price_id' => $quote->planPriceId,
            'duration_months' => $quote->durationMonths,
            'coupon_id' => $quote->couponId,
            'subtotal_paise' => $quote->price->subtotalPaise,
            'discount_paise' => $quote->price->discountPaise,
            'taxable_paise' => $quote->price->taxablePaise,
            'gst_rate_bps' => $quote->price->gstRateBps,
            'gst_paise' => $quote->price->gstPaise,
            'total_paise' => $quote->price->totalPaise,
            'currency' => 'INR',
            'status' => OrderStatus::Created,
            'gateway' => $this->gateway->name(),
            'expires_at' => now()->addMinutes(Settings::int(SettingKey::BillingOrderTtlMinutes)),
        ])->save();

        // Outside any transaction: our order row exists first, so a gateway order can always be
        // matched back to it (receipt = our code), and a gateway failure leaves a FAILED row, not a gap.
        try {
            $gatewayOrder = $this->gateway->createOrder($order->code, $order->total_paise, ['order' => $order->code, 'profile' => $profile->code]);
        } catch (PaymentGatewayUnavailable $e) {
            report($e);
            $order->forceFill(['status' => OrderStatus::Failed, 'failure_reason' => 'gateway_unavailable'])->save();

            throw CheckoutRefused::unavailable();
        }

        $order->forceFill(['gateway_order_id' => $gatewayOrder->id])->save();

        return $order;
    }
}
