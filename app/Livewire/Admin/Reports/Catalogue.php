<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Reports;

use App\Enums\ReportKey;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** A09 report catalogue (P9.1a): every report and the question it answers. Needs `reports.view`. */
#[Layout('layouts::admin', ['title' => 'Reports'])]
final class Catalogue extends Component
{
    public function mount(): void
    {
        $this->authorize('reports.view');
    }

    public function render(): View
    {
        return view('livewire.admin.reports.catalogue', ['reports' => ReportKey::cases()]);
    }
}
