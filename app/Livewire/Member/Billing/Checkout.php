<?php

declare(strict_types=1);

namespace App\Livewire\Member\Billing;

use App\Actions\Billing\ApplyCoupon;
use App\Actions\Billing\CreateOrder;
use App\Actions\Billing\QuoteCheckout;
use App\Actions\Billing\SimulateTestPayment;
use App\Contracts\PaymentGateway;
use App\Data\Billing\PriceQuoteData;
use App\Data\Content\SeoData;
use App\Enums\PlanCode;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Billing\CheckoutRefused;
use App\Models\Order;
use App\Models\User;
use App\Queries\Billing\PlanCatalog;
use App\Services\Admin\Impersonation;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Checkout (template checkout.php, PRD M10 / F04): plan summary, duration, a coupon checked live,
 * the GST line and the total — all from QuoteCheckout on the server; the page holds only the plan
 * KEY, a duration and a coupon code the server re-checks. "Continue to payment" creates the order
 * (CreateOrder) and opens Razorpay Checkout (UPI, cards, netbanking, wallets) — no card fields here.
 * With PAYMENT_DRIVER=fake a "Complete test payment" button stands in for Razorpay (no money).
 * Paying never trusts the browser's return: /billing/success polls the order.
 */
final class Checkout extends Component
{
    /** The plan KEY (PlanCode value) — from the route, never changed by the browser. */
    #[Locked]
    public string $plan = '';

    #[Url]
    public int $duration = 1;

    public string $coupon = '';

    /** The coupon the server accepted (ApplyCoupon); re-checked by every quote and by CreateOrder. */
    #[Locked]
    public ?string $appliedCoupon = null;

    /** The order "Continue to payment" created (test-mode panel, retry). */
    #[Locked]
    public ?string $orderCode = null;

    public ?string $refusal = null;

    public function mount(string $plan): void
    {
        $code = PlanCode::tryFrom(strtoupper($plan));
        abort_if($code === null || $code === PlanCode::Free, 404);
        $this->plan = $code->value;
        $this->duration = $this->validDuration($this->duration);
    }

    public function updatedDuration(): void
    {
        $this->duration = $this->validDuration($this->duration);
        $this->orderCode = null;
        $this->refusal = null;
    }

    /** Typed or changed: checked live (debounced in the view), counted against the hourly limit. */
    public function updatedCoupon(ApplyCoupon $apply): void
    {
        $this->applyCoupon($apply);
    }

    public function applyCoupon(ApplyCoupon $apply): void
    {
        $this->resetErrorBag('coupon');
        $this->orderCode = null;
        $code = trim($this->coupon);

        if ($code === '') {
            $this->appliedCoupon = null;

            return;
        }

        try {
            $quote = $apply->handle($this->member(), $this->planCode(), $this->duration, $code);
            $this->appliedCoupon = $quote->couponCode;
        } catch (ValidationException $e) {
            $this->appliedCoupon = null;
            $this->addError('coupon', $this->couponError($e));
        }
    }

    public function removeCoupon(): void
    {
        $this->coupon = '';
        $this->appliedCoupon = null;
        $this->orderCode = null;
        $this->resetErrorBag('coupon');
    }

    /** "Continue to payment": the server creates the order and hands the browser only what Razorpay needs. */
    public function pay(CreateOrder $create, PaymentGateway $gateway): void
    {
        $this->refusal = null;

        try {
            $order = $create->handle($this->member(), $this->planCode(), $this->duration, $this->appliedCoupon);
        } catch (CheckoutRefused|ImpersonationRestricted $e) {
            $this->refusal = $e->getMessage();

            return;
        } catch (ValidationException $e) {
            $this->appliedCoupon = null;
            $this->addError('coupon', $this->couponError($e));

            return;
        }

        $this->orderCode = $order->code;

        if (! $gateway->isTestMode()) {
            $this->dispatch('razorpay-open', options: [
                'key' => $gateway->publicKey(),
                'order_id' => $order->gateway_order_id,
                'amount' => $order->total_paise,
                'currency' => $order->currency,
                'name' => (string) config('oppam.site.name', 'Oppam Matrimony'),
                'description' => __(':plan membership', ['plan' => $order->plan()->value('name')]),
                'notes' => ['order' => $order->code],
            ], successUrl: route('member.billing.success', ['order' => $order->code]));
        }
    }

    /** PAYMENT_DRIVER=fake only: simulate Razorpay capturing (or refusing) the payment. */
    public function completeTestPayment(SimulateTestPayment $simulate, bool $succeed = true): void
    {
        $order = $this->ownOrder() ?? abort(404);

        try {
            $simulate->handle($this->member(), $order, $succeed);
        } catch (ImpersonationRestricted $e) {
            $this->refusal = $e->getMessage();

            return;
        }

        $this->redirectRoute('member.billing.success', ['order' => $order->code], navigate: true);
    }

    public function render(QuoteCheckout $quotes, PaymentGateway $gateway, Impersonation $impersonation): View
    {
        $quote = $this->quote($quotes);

        return view('livewire.member.billing.checkout', [
            'quote' => $quote,
            'durations' => $this->durations(),
            'features' => $this->features(),
            'available' => $gateway->isConfigured(),
            'testMode' => $gateway->isTestMode(),
            'support' => $impersonation->isActive(),
            'order' => $this->orderCode === null ? null : $this->ownOrder(),
            'displayZone' => (string) config('oppam.display_timezone'),
        ])->layout('layouts::member', [
            'seo' => SeoData::private('Checkout | Oppam Matrimony'),
        ]);
    }

    private function quote(QuoteCheckout $quotes): PriceQuoteData
    {
        try {
            return $quotes->handle($this->member(), $this->planCode(), $this->duration, $this->appliedCoupon);
        } catch (ValidationException $e) {
            // The accepted coupon no longer fits (another duration, its limit reached meanwhile).
            $this->appliedCoupon = null;
            $this->addError('coupon', $this->couponError($e));

            return $quotes->handle($this->member(), $this->planCode(), $this->duration);
        }
    }

    /** @return list<int> */
    private function durations(): array
    {
        return app(PlanCatalog::class)->durationsFor($this->planCode());
    }

    /** @return list<string> */
    private function features(): array
    {
        return app(PlanCatalog::class)->displayFeatures($this->planCode());
    }

    private function validDuration(int $months): int
    {
        $durations = $this->durations();
        abort_if($durations === [], 404);

        return in_array($months, $durations, true) ? $months : $durations[0];
    }

    private function ownOrder(): ?Order
    {
        $profile = $this->member()->profile;

        return $profile === null || $this->orderCode === null ? null
            : Order::query()->where('code', $this->orderCode)->where('profile_id', $profile->id)->first();
    }

    private function couponError(ValidationException $e): string
    {
        return (string) ($e->errors()['coupon'][0] ?? __('This code isn’t valid.'));
    }

    private function planCode(): PlanCode
    {
        return PlanCode::from($this->plan);
    }

    private function member(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
