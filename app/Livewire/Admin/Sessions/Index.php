<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Sessions;

use App\Actions\Admin\Staff\RevokeAdminSession;
use App\Models\AdminSession;
use App\Models\AdminUser;
use App\Services\Admin\AdminSessionRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Admin sessions (A01): your own live sessions, which you can end; with sessions.revoke_any, every
 * admin's live sessions. Ending a session signs its owner out on their next request.
 */
#[Layout('layouts::admin', ['title' => 'Sessions'])]
final class Index extends Component
{
    /** @return Collection<int, AdminSession> */
    #[Computed]
    public function sessions(): Collection
    {
        // Idle- or absolutely-expired sessions are dead even before their owner's next request revokes them.
        return AdminSession::query()
            ->live()
            ->where('last_seen_at', '>', now()->subMinutes(config('oppam.admin.idle_timeout_minutes')))
            ->where('created_at', '>', now()->subMinutes(config('oppam.admin.absolute_timeout_minutes')))
            ->with('admin:id,name,email')
            ->when(! $this->actor()->can('sessions.revoke_any'), fn ($query) => $query->where('admin_user_id', $this->actor()->id))
            ->latest('last_seen_at')
            ->limit(100)
            ->get();
    }

    #[Computed]
    public function currentSessionId(): ?string
    {
        return app(AdminSessionRegistry::class)->current(request())?->id;
    }

    public function revoke(string $sessionId, RevokeAdminSession $revoke): mixed
    {
        // Scoped lookup: without sessions.revoke_any only your own sessions exist here (404 otherwise).
        $session = AdminSession::query()
            ->live()
            ->when(! $this->actor()->can('sessions.revoke_any'), fn ($query) => $query->where('admin_user_id', $this->actor()->id))
            ->findOrFail($sessionId);

        $revoke->handle($this->actor(), $session);

        if ($session->id === $this->currentSessionId()) {
            return $this->redirectRoute('admin.login', navigate: false);
        }

        unset($this->sessions);
        $this->dispatch('toast', type: 'success', message: __('Session ended.'));

        return null;
    }

    public function render(): View
    {
        return view('livewire.admin.sessions.index');
    }

    private function actor(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
