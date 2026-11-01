<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Billing;

use App\Queries\Billing\StuckOrdersQuery;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/** A06 "stuck orders" (StuckOrdersQuery), oldest first. The actions live on the order's own page. */
#[Layout('layouts::admin', ['title' => 'Stuck orders'])]
final class StuckOrders extends Component
{
    use WithPagination;

    public function mount(): void
    {
        $this->authorize('billing.view');
    }

    public function render(StuckOrdersQuery $stuck): View
    {
        return view('livewire.admin.billing.stuck-orders', [
            'orders' => $stuck->query()->with(['plan:id,name', 'profile:id,code'])->oldest()->orderBy('id')->paginate(25),
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }
}
