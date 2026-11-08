<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Comms;

use App\Actions\Admin\Comms\BroadcastAnnouncement;
use App\Actions\Admin\Comms\CreateAnnouncement;
use App\Models\AdminUser;
use App\Models\Announcement;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Site-wide and targeted announcements with real-time broadcast (PRD §11 A14).
 */
#[Layout('layouts::admin', ['title' => 'Announcements'])]
final class Announcements extends Component
{
    use WithPagination;

    public bool $showModal = false;

    public string $title = '';

    public string $body = '';

    public string $actionUrl = '';

    public string $actionText = '';

    public string $startsAt = '';

    public string $endsAt = '';

    public string $targetPlan = '';

    public bool $isDismissible = true;

    public bool $broadcastNow = false;

    public function mount(): void
    {
        $this->authorize('campaigns.view');
    }

    public function openCreateModal(): void
    {
        $this->reset('title', 'body', 'actionUrl', 'actionText', 'startsAt', 'endsAt', 'targetPlan', 'broadcastNow');
        $this->isDismissible = true;
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function save(CreateAnnouncement $action): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string'],
            'actionUrl' => ['nullable', 'url', 'max:255'],
            'actionText' => ['nullable', 'string', 'max:50'],
            'startsAt' => ['nullable', 'date'],
            'endsAt' => ['nullable', 'date', 'after_or_equal:startsAt'],
            'targetPlan' => ['nullable', 'string', 'max:20'],
        ]);

        $admin = $this->admin();

        $action->handle($admin, [
            'title' => $this->title,
            'body' => $this->body,
            'action_url' => $this->actionUrl,
            'action_text' => $this->actionText,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
            'target_plan' => $this->targetPlan,
            'is_dismissible' => $this->isDismissible,
            'broadcast_now' => $this->broadcastNow,
        ]);

        $this->showModal = false;
        $this->dispatch('toast', type: 'success', message: __('Announcement created successfully.'));
    }

    public function broadcast(string $id, BroadcastAnnouncement $action): void
    {
        $admin = $this->admin();
        /** @var Announcement $announcement */
        $announcement = Announcement::query()->findOrFail($id);

        $action->handle($admin, $announcement);
        $this->dispatch('toast', type: 'success', message: __('Announcement broadcasted live to online members.'));
    }

    public function toggleActive(string $id): void
    {
        $this->authorize('notifications.broadcast');
        /** @var Announcement $announcement */
        $announcement = Announcement::query()->findOrFail($id);
        $announcement->update(['is_active' => ! $announcement->is_active]);
        $this->dispatch('toast', type: 'info', message: __('Announcement status updated.'));
    }

    public function render(): View
    {
        return view('livewire.admin.comms.announcements', [
            'announcements' => Announcement::query()->latest()->paginate(15),
        ]);
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser $user */
        $user = Auth::guard('admin')->user();

        return $user;
    }
}
