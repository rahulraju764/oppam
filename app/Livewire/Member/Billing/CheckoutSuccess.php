<?php

declare(strict_types=1);

namespace App\Livewire\Member\Billing;

use App\Actions\Billing\ReconcileOrder;
use App\Data\Content\SeoData;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * /billing/success/{order} (F04 "client redirect → /billing/success polls order status until PAID
 * — never trusts redirect alone"). The member's own order only (404 otherwise). While the order
 * waits it re-reads every 2 s; after a few reads it asks the gateway directly (ReconcileOrder,
 * server to server). The `.entitlements.changed` signal re-renders at once. After ~3 minutes it
 * stops polling and offers "Check again".
 */
final class CheckoutSuccess extends Component
{
    /** Ask the gateway after this many 2-second reads without a webhook. */
    private const RECONCILE_AFTER_POLLS = 3;

    private const MAX_POLLS = 90;

    #[Locked]
    public string $order = '';

    #[Locked]
    public int $polls = 0;

    public function mount(string $order): void
    {
        $this->order = $order;
        $this->ownOrder();   // 404 for anyone else's order
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        return [
            'echo-private:App.Models.User.'.$this->member()->id.',.entitlements.changed' => '$refresh',
            'realtime-reconnected' => '$refresh',
        ];
    }

    /** wire:poll while waiting. */
    public function check(ReconcileOrder $reconcile): void
    {
        $this->polls = min($this->polls + 1, self::MAX_POLLS);
        $order = $this->ownOrder();

        if ($order->status->isPayable() && $this->polls >= self::RECONCILE_AFTER_POLLS) {
            $reconcile->handle($this->member(), $order);
        }
    }

    /** "Check again" after polling stopped. */
    public function retry(ReconcileOrder $reconcile): void
    {
        $this->polls = self::RECONCILE_AFTER_POLLS;
        $reconcile->handle($this->member(), $this->ownOrder());
    }

    public function render(): View
    {
        $order = $this->ownOrder()->load(['plan', 'invoice', 'subscription']);
        $latest = $order->payments()->latest()->first();

        $state = match (true) {
            $order->status === OrderStatus::Paid => 'paid',
            $latest?->status === PaymentStatus::Failed => 'failed',
            $order->status === OrderStatus::Expired, $order->status === OrderStatus::Failed => 'closed',
            default => 'waiting',
        };

        return view('livewire.member.billing.checkout-success', [
            'record' => $order,
            'state' => $state,
            'polling' => $state === 'waiting' && $this->polls < self::MAX_POLLS,
            'displayZone' => (string) config('oppam.display_timezone'),
        ])->layout('layouts::member', [
            'seo' => SeoData::private('Payment | Oppam Matrimony'),
        ]);
    }

    private function ownOrder(): Order
    {
        $profile = $this->member()->profile ?? abort(404);

        return Order::query()->where('code', $this->order)->where('profile_id', $profile->id)->first() ?? abort(404);
    }

    private function member(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
