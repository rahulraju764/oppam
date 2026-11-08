<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Comms;

use App\Actions\Admin\Comms\ApproveCampaign;
use App\Actions\Admin\Comms\CancelCampaign;
use App\Actions\Admin\Comms\SendCampaign;
use App\Models\AdminUser;
use App\Models\Campaign;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lists and manages outbound marketing/engagement campaigns (PRD §11 A14).
 */
#[Layout('layouts::admin', ['title' => 'Campaigns'])]
final class Campaigns extends Component
{
    use WithPagination;

    public string $channelFilter = 'all';

    public string $statusFilter = 'all';

    public function mount(): void
    {
        $this->authorize('campaigns.view');
    }

    public function approve(string $id, ApproveCampaign $action): void
    {
        $admin = $this->admin();
        /** @var Campaign $campaign */
        $campaign = Campaign::query()->findOrFail($id);

        try {
            $action->handle($admin, $campaign);
            $this->dispatch('toast', type: 'success', message: __('Campaign approved.'));
        } catch (ValidationException $e) {
            $this->dispatch('toast', type: 'danger', message: $e->getMessage());
        }
    }

    public function send(string $id, SendCampaign $action): void
    {
        $admin = $this->admin();
        /** @var Campaign $campaign */
        $campaign = Campaign::query()->findOrFail($id);

        try {
            $action->handle($admin, $campaign);
            $this->dispatch('toast', type: 'success', message: __('Campaign sending started.'));
        } catch (ValidationException $e) {
            $this->dispatch('toast', type: 'danger', message: $e->getMessage());
        }
    }

    public function cancel(string $id, CancelCampaign $action): void
    {
        $admin = $this->admin();
        /** @var Campaign $campaign */
        $campaign = Campaign::query()->findOrFail($id);

        try {
            $action->handle($admin, $campaign);
            $this->dispatch('toast', type: 'info', message: __('Campaign cancelled.'));
        } catch (ValidationException $e) {
            $this->dispatch('toast', type: 'danger', message: $e->getMessage());
        }
    }

    public function render(): View
    {
        $query = Campaign::query()->with(['creator', 'approver'])->latest();

        if ($this->channelFilter !== 'all') {
            $query->where('channel', $this->channelFilter);
        }

        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        return view('livewire.admin.comms.campaigns', [
            'campaigns' => $query->paginate(15),
        ]);
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser $user */
        $user = Auth::guard('admin')->user();

        return $user;
    }
}
