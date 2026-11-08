<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Comms;

use App\Actions\Admin\Comms\ResendFailedMessage;
use App\Models\AdminUser;
use App\Models\OutboundMessage;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Audit delivery log for all outbound transactional and marketing messages (PRD §11 A14).
 */
#[Layout('layouts::admin', ['title' => 'Delivery Log'])]
final class DeliveryLog extends Component
{
    use WithPagination;

    public string $channelFilter = 'all';

    public string $statusFilter = 'all';

    public string $search = '';

    public function mount(): void
    {
        $this->authorize('campaigns.view');
    }

    public function resend(string $id, ResendFailedMessage $action): void
    {
        $admin = $this->admin();
        /** @var OutboundMessage $message */
        $message = OutboundMessage::query()->findOrFail($id);

        try {
            $action->handle($admin, $message);
            $this->dispatch('toast', type: 'success', message: __('Message queued for resend.'));
        } catch (ValidationException $e) {
            $this->dispatch('toast', type: 'danger', message: $e->getMessage());
        }
    }

    public function render(): View
    {
        $query = OutboundMessage::query()->latest('sent_at');

        if ($this->channelFilter !== 'all') {
            $query->where('channel', $this->channelFilter);
        }

        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        if (! empty(trim($this->search))) {
            $query->where('recipient', 'like', '%'.trim($this->search).'%');
        }

        return view('livewire.admin.comms.delivery-log', [
            'messages' => $query->paginate(20),
        ]);
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser $user */
        $user = Auth::guard('admin')->user();

        return $user;
    }
}
