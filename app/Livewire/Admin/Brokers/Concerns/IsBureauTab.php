<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Brokers\Concerns;

use App\Models\Broker;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;

/**
 * A lazy tab on the A07 bureau page: authorizes its own permission (it is its own Livewire
 * component, reachable without the parent page), keeps the bureau code #[Locked] and shows a
 * skeleton while it loads. An unknown code is 404.
 */
trait IsBureauTab
{
    #[Locked]
    public string $code = '';

    protected function openTab(string $code, string $permission = 'brokers.view'): void
    {
        $this->authorize($permission);
        $this->code = strtoupper($code);
        $this->broker();
    }

    protected function broker(): Broker
    {
        return Broker::query()->where('code', $this->code)->firstOrFail();
    }

    public function placeholder(): View
    {
        return view('livewire.admin.members.tabs.placeholder');
    }
}
