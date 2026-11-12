<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Reports;

use App\Models\AdminExport;
use App\Models\AdminUser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * A09 "my exports" (P9.1a): the admin's OWN background report exports — being prepared, ready
 * (download for 24 hours), failed or expired. Needs `reports.export`.
 */
#[Layout('layouts::admin', ['title' => 'Report exports'])]
final class Exports extends Component
{
    public const SHOWN = 30;

    public function mount(): void
    {
        $this->authorize('reports.export');
    }

    public function render(): View
    {
        /** @var AdminUser $admin */
        $admin = Auth::guard('admin')->user();

        return view('livewire.admin.reports.exports', [
            'exports' => AdminExport::query()->where('admin_user_id', $admin->id)->latest()->limit(self::SHOWN)->get(),
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }
}
