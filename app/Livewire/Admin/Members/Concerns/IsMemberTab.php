<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Members\Concerns;

use Illuminate\Contracts\View\View;

/**
 * A lazy tab on the A03 member page: authorizes members.view on its own (it is its own Livewire
 * component, reachable without the parent page) and shows a skeleton while it loads.
 */
trait IsMemberTab
{
    use LoadsMember;

    protected function openTab(string $code): void
    {
        $this->authorize('members.view');
        $this->code = strtoupper($code);
        $this->member();   // 404 for an unknown code
    }

    public function placeholder(): View
    {
        return view('livewire.admin.members.tabs.placeholder');
    }
}
