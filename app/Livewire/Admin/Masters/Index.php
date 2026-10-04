<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Masters;

use App\Domain\Masters\MasterLists;
use App\Models\Masters\MasterOption;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** A11: every editable master list, grouped by section, with how many rows each has. */
#[Layout('layouts::admin', ['title' => 'Master data'])]
final class Index extends Component
{
    public function mount(): void
    {
        $this->authorize('masters.view');
    }

    public function render(): View
    {
        $groupCounts = MasterOption::query()->selectRaw('`group`, COUNT(*) AS aggregate')->groupBy('group')->pluck('aggregate', 'group');
        $sections = [];

        foreach (MasterLists::all() as $list) {
            $count = $list->group !== null ? (int) ($groupCounts[$list->group] ?? 0) : $list->model::query()->count();
            $sections[$list->section][] = ['key' => $list->key, 'label' => $list->label, 'count' => $count, 'scoped' => $list->hasParent()];
        }

        return view('livewire.admin.masters.index', ['sections' => $sections]);
    }
}
