<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Enums\ProfileStatus;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Admin landing page. The real A02 dashboard (KPI snapshots, live queue counts over
 * admin.dashboard / admin.queues) arrives in P3.5; for now a few cheap counts for admins with
 * dashboard.view, and a welcome for everyone.
 */
#[Layout('layouts::admin', ['title' => 'Dashboard'])]
final class Dashboard extends Component
{
    /** @return array<string, int>|null */
    #[Computed]
    public function counts(): ?array
    {
        if (! auth('admin')->user()?->can('dashboard.view')) {
            return null;
        }

        return [
            'members' => User::query()->count(),
            'active' => Profile::query()->where('status', ProfileStatus::Active->value)->count(),
            'pending' => Profile::query()->where('status', ProfileStatus::PendingReview->value)->count(),
        ];
    }

    public function render(): View
    {
        return view('livewire.admin.dashboard');
    }
}
