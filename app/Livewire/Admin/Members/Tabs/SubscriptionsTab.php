<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Members\Tabs;

use App\Livewire\Admin\Members\Concerns\IsMemberTab;
use App\Models\Order;
use App\Models\Subscription;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * A03 "Subscriptions": the member's plans, newest first — paid or complimentary, running,
 * paused (suspension) or ended. Orders and refunds join this page with billing (P5).
 */
final class SubscriptionsTab extends Component
{
    use IsMemberTab;

    public function mount(string $code): void
    {
        $this->openTab($code);
    }

    public function render(): View
    {
        return view('livewire.admin.members.tabs.subscriptions-tab', [
            'subscriptions' => Subscription::query()->where('profile_id', $this->profile()->id)
                ->with('plan:id,code,name')->latest('starts_at')->limit(50)->get(),
            'orders' => auth('admin')->user()?->can('billing.view') === true
                ? Order::query()->where('profile_id', $this->profile()->id)->with('plan:id,name')->latest()->limit(50)->get()
                : null,
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }
}
