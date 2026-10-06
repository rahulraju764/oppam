<?php

declare(strict_types=1);

namespace App\Livewire\Shared;

use App\Actions\Notifications\UpdateNotifications;
use App\Data\Notifications\NotificationItemData;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The header bell (PRD M08 UI), desktop and mobile. On page load it runs ONE query — the unread
 * count; the latest ITEMS load only when the desktop dropdown opens (M08 acceptance). Live: a new
 * notification re-reads the count (and the open list); `.notifications.read` from another tab sets
 * the count it carries; a socket reconnect re-reads. In polling-fallback mode the view refreshes it
 * every 30 s. The mobile variant is a link with the live count.
 */
final class NotificationBell extends Component
{
    public const ITEMS = 10;

    #[Locked]
    public string $variant = 'desktop';

    #[Locked]
    public int $unread = 0;

    /** @var list<array<string, mixed>>|null null until the dropdown is first opened */
    #[Locked]
    public ?array $items = null;

    public function mount(string $variant = 'desktop'): void
    {
        $this->variant = $variant === 'mobile' ? 'mobile' : 'desktop';
        $this->unread = app(UpdateNotifications::class)->unreadCount($this->member());
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        $channel = 'echo-private:App.Models.User.'.$this->member()->id;

        return [
            $channel.',.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated' => 'refreshBell',
            $channel.',.notifications.read' => 'syncCount',
            'realtime-reconnected' => 'refreshBell',
            'notifications-changed' => 'refreshBell',   // read / deleted on the /notifications page
        ];
    }

    public function open(): void
    {
        $this->items = DatabaseNotification::query()
            ->where('notifiable_type', $this->member()->getMorphClass())
            ->where('notifiable_id', $this->member()->id)
            ->latest()->orderByDesc('id')->limit(self::ITEMS)->get()
            ->map(fn (DatabaseNotification $n): array => NotificationItemData::fromModel($n)->toArray())
            ->values()->all();
    }

    public function refreshBell(): void
    {
        $this->unread = app(UpdateNotifications::class)->unreadCount($this->member());
        if ($this->items !== null) {
            $this->open();
        }
    }

    /** @param  array<string, mixed>  $payload */
    public function syncCount(array $payload = []): void
    {
        $this->unread = max(0, (int) ($payload['unread_count'] ?? 0));
        if ($this->items !== null) {
            $this->open();
        }
    }

    public function markAllRead(UpdateNotifications $notifications): void
    {
        try {
            $notifications->markRead($this->member());
        } catch (ImpersonationRestricted $restricted) {
            $this->dispatch('toast', title: $restricted->getMessage(), type: 'danger');

            return;
        }

        $this->refreshBell();
        $this->dispatch('notifications-all-read');   // the /notifications list, if it is open
    }

    public function render(): View
    {
        return view('livewire.shared.notification-bell');
    }

    private function member(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
