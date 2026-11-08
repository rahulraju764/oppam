<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Settings;

use App\Queries\Billing\PlanCatalog;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Entitlement matrix comparison preview (PRD §11 A15).
 * Previews what members see on the pricing comparison table, linking to A06 plan features editor.
 */
#[Layout('layouts::admin', ['title' => 'Entitlements Matrix'])]
final class EntitlementsMatrix extends Component
{
    public function mount(): void
    {
        $this->authorize('settings.view');
    }

    public function render(PlanCatalog $catalog): View
    {
        return view('livewire.admin.settings.entitlements-matrix', [
            'comparison' => $catalog->comparison(),
        ]);
    }
}
