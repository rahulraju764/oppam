<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Billing;

use App\Actions\Admin\Billing\ManageRefund;
use App\Actions\Admin\Billing\ResolveStuckOrder;
use App\Domain\Billing\RefundBalance;
use App\Enums\PaymentStatus;
use App\Enums\RefundCategory;
use App\Exceptions\Admin\BillingRefused;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\ValueObjects\Money;
use DateTimeInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A06 order detail: the lifecycle timeline, payments, refunds and the stored webhooks (payer details
 * were redacted when stored). Actions: request a refund; for an unpaid order re-poll, mark failed,
 * force-activate. Every action re-checks its permission in its Action.
 */
#[Layout('layouts::admin', ['title' => 'Order'])]
final class OrderShow extends Component
{
    #[Locked]
    public string $code = '';

    public string $refundPayment = '';

    public string $refundCategory = '';

    public string $refundAmount = '';

    public string $reason = '';

    public function mount(string $order): void
    {
        $this->authorize('billing.view');
        $this->code = Order::query()->where('code', $order)->firstOrFail()->code;
    }

    public function openRefund(RefundBalance $balance, string $gatewayPaymentId): void
    {
        $this->authorize('billing.refund');
        $payment = $this->payment($gatewayPaymentId);
        $this->resetErrorBag();
        $this->refundPayment = $payment->gateway_payment_id;
        $this->refundCategory = RefundCategory::Goodwill->value;
        $this->refundAmount = number_format($balance->left($payment) / 100, 2, '.', '');
        $this->reason = '';
        $this->dispatch('open-modal', name: 'refund-request');
    }

    public function requestRefund(ManageRefund $refunds): void
    {
        $this->resetErrorBag();
        $paise = Money::parseRupees($this->refundAmount) ?? throw ValidationException::withMessages(['refundAmount' => __('Enter an amount in rupees.')]);
        $category = RefundCategory::tryFrom($this->refundCategory) ?? throw ValidationException::withMessages(['refundCategory' => __('Choose a category.')]);

        $this->act(fn () => $refunds->request($this->admin(), $this->order(), $this->refundPayment, $category, $paise, $this->reason),
            'refund-request', __('Refund requested. Another admin must approve it.'));
    }

    public function repoll(ResolveStuckOrder $resolve): void
    {
        $this->act(fn () => $resolve->repoll($this->admin(), $this->order()), null, __('Checked with the gateway.'));
    }

    public function markFailed(ResolveStuckOrder $resolve): void
    {
        $this->act(fn () => $resolve->markFailed($this->admin(), $this->order(), $this->reason), 'order-fail', __('Order marked failed.'));
    }

    public function forceActivate(ResolveStuckOrder $resolve): void
    {
        $this->act(fn () => $resolve->forceActivate($this->admin(), $this->order(), $this->reason), 'order-force', __('Order activated.'));
    }

    public function render(RefundBalance $balance): View
    {
        $order = $this->order()->load(['plan:id,name', 'profile:id,code', 'coupon:id,code', 'invoice', 'subscription', 'payments', 'refunds.requestedBy:id,name', 'refunds.decidedBy:id,name', 'refunds.creditNote']);

        return view('livewire.admin.billing.order-show', [
            'order' => $order,
            'timeline' => $this->timeline($order),
            'refundable' => $order->payments->mapWithKeys(fn (Payment $p): array => [$p->id => $p->status === PaymentStatus::Captured ? $balance->left($p) : 0])->all(),
            'webhooks' => $order->gateway_order_id === null ? collect() : PaymentWebhookEvent::query()->where('gateway_order_id', $order->gateway_order_id)->latest()->limit(50)->get(),
            'categories' => RefundCategory::options(),
            'canForce' => $this->admin()->can('billing.force_activate') && $this->admin()->hasRole('super_admin'),
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }

    /**
     * Newest last. Dates are UTC here; the view shows them in IST.
     *
     * @return list<array{at: DateTimeInterface, text: string}>
     */
    private function timeline(Order $order): array
    {
        $events = [['at' => $order->created_at, 'text' => __('Order created')]];

        foreach ($order->payments as $payment) {
            $events[] = ['at' => $payment->captured_at ?? $payment->created_at, 'text' => __('Payment :id: :status, ₹:amount', [
                'id' => $payment->gateway_payment_id, 'status' => $payment->status->value, 'amount' => Money::paise($payment->amount_paise)->format(),
            ])];
        }
        if ($order->paid_at !== null) {
            $events[] = ['at' => $order->paid_at, 'text' => __('Paid, plan activated')];
        }
        if ($order->invoice !== null) {
            $events[] = ['at' => $order->invoice->issued_at, 'text' => __('Invoice :number issued', ['number' => $order->invoice->number])];
        }
        foreach ($order->refunds as $refund) {
            $events[] = ['at' => $refund->created_at, 'text' => __('Refund :code requested', ['code' => $refund->code])];
            if ($refund->decided_at !== null) {
                $events[] = ['at' => $refund->decided_at, 'text' => __('Refund :code decided: :status', ['code' => $refund->code, 'status' => $refund->status->label()])];
            }
            if ($refund->processed_at !== null) {
                $events[] = ['at' => $refund->processed_at, 'text' => __('Refund :code processed', ['code' => $refund->code])];
            }
            if ($refund->creditNote !== null) {
                $events[] = ['at' => $refund->creditNote->issued_at, 'text' => __('Credit note :number issued', ['number' => $refund->creditNote->number])];
            }
        }

        usort($events, fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        return $events;
    }

    private function act(callable $action, ?string $modal, string $done): void
    {
        $this->resetErrorBag();

        try {
            $action();
        } catch (BillingRefused $refused) {
            if ($modal !== null) {
                $this->dispatch('close-modal', name: $modal);
            }
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        }

        if ($modal !== null) {
            $this->dispatch('close-modal', name: $modal);
        }
        $this->dispatch('toast', type: 'success', title: $done);
        $this->reset('reason');
    }

    private function order(): Order
    {
        return Order::query()->where('code', $this->code)->firstOrFail();
    }

    private function payment(string $gatewayPaymentId): Payment
    {
        return Payment::query()->where('order_id', $this->order()->id)->where('gateway_payment_id', $gatewayPaymentId)->firstOrFail();
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
