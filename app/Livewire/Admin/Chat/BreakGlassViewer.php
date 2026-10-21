<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Chat;

use App\Actions\Admin\Chat\EndBreakGlass;
use App\Actions\Admin\Chat\ReadBreakGlassTranscript;
use App\Exceptions\Chat\BreakGlassEnded;
use App\Models\AdminUser;
use App\Models\BreakGlassGrant;
use App\Models\Conversation;
use App\Queries\Chat\BreakGlassTranscript;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The break-glass viewer (PRD A13 "time-limited (30 min), watermarked viewer, audited"). Only the
 * admin the grant belongs to (anyone else: 404). Opening reads the newest page, "Load earlier" the
 * page before — each through ReadBreakGlassTranscript, which audits it; re-renders show the same
 * audited range again (fromId … toId, locked) and nothing newer. Read only; every message sits under
 * a watermark naming the admin, the time and the session. When the grant ends (time up — the
 * countdown re-renders — or "End now") the transcript is gone from the page.
 */
#[Layout('layouts::admin', ['title' => 'Break-glass read'])]
final class BreakGlassViewer extends Component
{
    #[Locked]
    public string $grantId = '';

    #[Locked]
    public ?string $fromId = null;

    #[Locked]
    public ?string $toId = null;

    public function mount(string $grant, ReadBreakGlassTranscript $read): void
    {
        Gate::authorize('chat.read_conversation');
        $row = $this->grant($grant);
        $this->grantId = $row->id;

        try {
            $page = $read->handle($this->admin(), $row);
            [$this->fromId, $this->toId] = [$page->oldestId, $page->newestId];
        } catch (BreakGlassEnded) {
            // Shown as ended by render().
        }
    }

    public function loadEarlier(ReadBreakGlassTranscript $read): void
    {
        if ($this->fromId === null) {
            return;
        }

        try {
            $page = $read->handle($this->admin(), $this->grant(), $this->fromId);
            $this->fromId = $page->oldestId ?? $this->fromId;
        } catch (BreakGlassEnded $ended) {
            $this->dispatch('toast', type: 'info', title: $ended->getMessage());
        }
    }

    public function end(EndBreakGlass $end): void
    {
        $end->handle($this->admin(), $this->grant());
        $this->dispatch('toast', type: 'success', title: __('Break-glass session ended.'));
    }

    public function render(BreakGlassTranscript $transcript): View
    {
        Gate::authorize('chat.read_conversation');
        $grant = $this->grant();
        $thread = Conversation::query()->find($grant->conversation_id);
        $active = $grant->isActive() && $thread !== null;
        $admin = $this->admin();

        return view('livewire.admin.chat.break-glass-viewer', [
            'grant' => $grant,
            'active' => $active,
            'members' => $thread?->memberCodesLabel() ?? '—',
            'page' => $active && $this->fromId !== null && $this->toId !== null ? $transcript->range($thread, $this->fromId, $this->toId) : null,
            'secondsLeft' => $active ? max(0, (int) now()->diffInSeconds($grant->expires_at, false)) : 0,
            'watermark' => $admin->email.' · '.now()->setTimezone((string) config('oppam.display_timezone'))->format('d M Y H:i').' IST · '.substr($grant->id, -8),
        ]);
    }

    private function grant(?string $id = null): BreakGlassGrant
    {
        $id ??= $this->grantId;
        $grant = preg_match('/^[0-9a-z]{26}$/i', $id) === 1 ? BreakGlassGrant::query()->find(strtolower($id)) : null;

        // Another admin's grant is as good as missing.
        return $grant !== null && $grant->admin_user_id === $this->admin()->id ? $grant : abort(404);
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
