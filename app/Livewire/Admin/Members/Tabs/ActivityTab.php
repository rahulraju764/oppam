<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Members\Tabs;

use App\Livewire\Admin\Members\Concerns\IsMemberTab;
use App\Models\LoginEvent;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A03 "Activity (logins, devices, IPs)": the member's sign-ins, newest first. The device is
 * shown as a short fingerprint of the device cookie hash (never the cookie itself).
 */
final class ActivityTab extends Component
{
    use IsMemberTab;
    use WithPagination;

    public function mount(string $code): void
    {
        $this->openTab($code);
    }

    public function render(): View
    {
        return view('livewire.admin.members.tabs.activity-tab', [
            'events' => LoginEvent::query()->where('user_id', $this->member()->id)
                ->latest('created_at')->orderByDesc('id')->paginate(20, pageName: 'activity'),
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }
}
