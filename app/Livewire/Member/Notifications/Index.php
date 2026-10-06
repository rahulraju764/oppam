<?php

declare(strict_types=1);

namespace App\Livewire\Member\Notifications;

use App\Actions\Notifications\UpdateNotifications;
use App\Data\Content\SeoData;
use App\Data\Notifications\NotificationItemData;
use App\Enums\NotificationGroup;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\Cursor;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * /notifications (PRD M08 UI, template notifications.php — 3/6/3: type filters | list | ads):
 * filter by group and Unread (in the URL), 20 at a time with "load more" / infinite scroll (cursor),
 * grouped Today / Yesterday / Earlier; mark one or all read, delete one. Only the member's own
 * notifications are ever read or changed (UpdateNotifications scopes every write).
 *
 * The loaded list is never thrown away under the reader: marking read and deleting change the one
 * row in place, and a new live notification (or a reconnect) re-reads the list only while the
 * member is still on the first 20 — further down it shows a "new notifications" button instead.
 */
final class Index extends Component
{
    public const PER_PAGE = 20;

    #[Url(as: 'type', except: '')]
    public string $group = '';

    #[Url(except: false)]
    public bool $unread = false;

    /** @var list<array<string, mixed>> */
    #[Locked]
    public array $items = [];

    #[Locked]
    public ?string $cursor = null;

    /** Something arrived while the member was past the first page (see class comment). */
    #[Locked]
    public bool $hasNew = false;

    public function mount(): void
    {
        $this->load(append: false);
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        return [
            'echo-private:App.Models.User.'.$this->member()->id.',.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated' => 'arrived',
            'realtime-reconnected' => 'arrived',
            'notifications-all-read' => 'showAllRead',   // "Mark all as read" in the header bell
        ];
    }

    public function updatedGroup(): void
    {
        $this->reload();
    }

    public function updatedUnread(): void
    {
        $this->reload();
    }

    public function reload(): void
    {
        $this->cursor = null;
        $this->hasNew = false;
        $this->load(append: false);
    }

    public function arrived(): void
    {
        if (count($this->items) <= self::PER_PAGE) {
            $this->reload();
        } else {
            $this->hasNew = true;
        }
    }

    public function loadMore(): void
    {
        if ($this->cursor !== null) {
            $this->load(append: true);
        }
    }

    public function markRead(string $id, UpdateNotifications $notifications): void
    {
        if (! $this->attempt(fn () => $notifications->markRead($this->member(), $id))) {
            return;
        }

        // In the "Unread only" view a read row leaves the list; otherwise it just loses its mark.
        $this->items = collect($this->items)
            ->reject(fn (array $item): bool => $this->unread && $item['id'] === $id)
            ->map(fn (array $item): array => $item['id'] === $id ? [...$item, 'unread' => false] : $item)
            ->values()->all();
        $this->dispatch('notifications-changed');
    }

    public function markAllRead(UpdateNotifications $notifications): void
    {
        if (! $this->attempt(fn () => $notifications->markRead($this->member()))) {
            return;
        }

        $this->showAllRead();
        $this->dispatch('notifications-changed');
    }

    public function delete(string $id, UpdateNotifications $notifications): void
    {
        if (! $this->attempt(fn () => $notifications->delete($this->member(), $id))) {
            return;
        }

        $this->items = collect($this->items)->reject(fn (array $item): bool => $item['id'] === $id)->values()->all();
        $this->dispatch('notifications-changed');
    }

    /** Everything is read now (here or from the bell): show it without re-reading the list. */
    public function showAllRead(): void
    {
        if ($this->unread) {
            $this->items = [];
            $this->cursor = null;

            return;
        }

        $this->items = array_map(fn (array $item): array => [...$item, 'unread' => false], $this->items);
    }

    public function render(UpdateNotifications $notifications): View
    {
        return view('livewire.member.notifications.index', [
            'groups' => NotificationGroup::cases(),
            'current' => NotificationGroup::tryFrom($this->group),
            'unreadCount' => $notifications->unreadCount($this->member()),
            'byDay' => collect($this->items)->groupBy('day')->all(),
        ])->layout('layouts::member', [
            'seo' => SeoData::private('Notifications | Oppam Matrimony'),
        ]);
    }

    /** Run a change; an admin support session may not make it (toast, nothing changed). */
    private function attempt(callable $change): bool
    {
        try {
            $change();
        } catch (ImpersonationRestricted $restricted) {
            $this->dispatch('toast', title: $restricted->getMessage(), type: 'danger');

            return false;
        }

        return true;
    }

    private function load(bool $append): void
    {
        $member = $this->member();
        $group = NotificationGroup::tryFrom($this->group);
        $this->group = $group === null ? '' : $group->value;

        $page = DatabaseNotification::query()
            ->where('notifiable_type', $member->getMorphClass())
            ->where('notifiable_id', $member->id)
            ->when($group !== null, fn ($q) => $q->whereIn('type', $group->events()))
            ->when($this->unread, fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE, ['*'], 'cursor', $append && $this->cursor !== null ? Cursor::fromEncoded($this->cursor) : null);

        $rows = collect($page->items())->map(fn (DatabaseNotification $n): array => NotificationItemData::fromModel($n)->toArray())->values()->all();

        $this->items = $append ? [...$this->items, ...$rows] : $rows;
        $this->cursor = $page->nextCursor()?->encode();
    }

    private function member(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
