<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Billing;

use App\Enums\OrderStatus;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** A06 orders list: by order or profile code and status, newest first; the monthly GST CSV. */
#[Layout('layouts::admin', ['title' => 'Orders'])]
final class OrdersIndex extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $q = '';

    #[Url(except: '')]
    public string $status = '';

    public function mount(): void
    {
        $this->authorize('billing.view');
    }

    public function updatedQ(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $term = strtoupper(trim($this->q));

        return view('livewire.admin.billing.orders-index', [
            'orders' => Order::query()
                ->when(OrderStatus::tryFrom($this->status), fn ($q, OrderStatus $status) => $q->where('status', $status))
                ->when(preg_match('/^ORD\d+$/', $term) === 1, fn ($q) => $q->where('code', $term))
                ->when(preg_match('/^OPM\d+$/', $term) === 1, fn ($q) => $q->whereHas('profile', fn ($p) => $p->where('code', $term)))
                ->with(['plan:id,name', 'profile:id,code'])
                ->latest()->orderByDesc('id')->paginate(25),
            'statuses' => collect(OrderStatus::cases())->mapWithKeys(fn (OrderStatus $s): array => [$s->value => $s->label()])->all(),
            'lastMonth' => CarbonImmutable::now((string) config('oppam.display_timezone'))->startOfMonth()->subMonth()->format('Y-m'),
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }
}
