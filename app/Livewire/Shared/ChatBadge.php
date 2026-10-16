<?php

declare(strict_types=1);

namespace App\Livewire\Shared;

use App\Actions\Chat\MarkMessagesDelivered;
use App\Models\Profile;
use App\Models\User;
use App\Queries\Chat\UnreadChats;
use App\Services\Admin\Impersonation;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The header's Messages link with the live unread count (PRD M07 `Shared\ChatBadge`), desktop and
 * mobile. Live on the member's own `inbox.{profile}`: `.conversation.updated` carries the new total;
 * a message from the other member pops a toast unless that thread is open on screen ("New message
 * from Anjali — Reply"). The DESKTOP badge (always on the page, also when hidden by CSS on phones)
 * is the one that acknowledges delivery — on page load when something is unread, and on each new
 * message — so the sender's ✓ becomes ✓✓ (MarkMessagesDelivered); the mobile one only counts.
 * The count also goes into the browser tab title.
 */
final class ChatBadge extends Component
{
    #[Locked]
    public string $variant = 'desktop';

    #[Locked]
    public int $unread = 0;

    /** On /messages (decided on the page request: a later Livewire update runs on another route). */
    #[Locked]
    public bool $current = false;

    public function mount(string $variant = 'desktop'): void
    {
        $this->variant = $variant === 'mobile' ? 'mobile' : 'desktop';
        $this->current = request()->routeIs('member.messages');
        $me = $this->me();
        $this->unread = $me === null ? 0 : app(UnreadChats::class)->total($me);

        if ($me !== null && $this->unread > 0 && $this->variant === 'desktop') {
            app(MarkMessagesDelivered::class)->handle($me);
        }
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        $me = $this->me();
        // A support session may not follow the member's inbox (InboxChannel refuses it).
        if ($me === null || app(Impersonation::class)->isActive()) {
            return ['chat-inbox-changed' => 'recount'];
        }

        return [
            'echo-private:inbox.'.$me->id.',.conversation.updated' => 'onUpdated',
            'realtime-reconnected' => 'recount',
            'chat-inbox-changed' => 'recount',
        ];
    }

    /** @param  array<string, mixed>  $payload */
    public function onUpdated(array $payload = []): void
    {
        $this->unread = max(0, (int) ($payload['total_unread'] ?? 0));

        if ($this->variant !== 'desktop' || ($payload['from_me'] ?? true) !== false) {
            return;
        }

        $me = $this->me();
        if ($me !== null) {
            app(MarkMessagesDelivered::class)->handle($me);
        }

        $code = (string) ($payload['with_code'] ?? '');
        if (preg_match('/^OPM\d{1,10}$/', $code) === 1 && (int) ($payload['unread'] ?? 0) > 0) {
            $this->dispatch('chat-new-message', code: $code, title: __('New message from :name', ['name' => (string) ($payload['name'] ?? '')]),
                body: (string) ($payload['preview'] ?? ''), url: route('member.messages', ['with' => $code]));
        }
    }

    public function recount(): void
    {
        $me = $this->me();
        $this->unread = $me === null ? 0 : app(UnreadChats::class)->total($me);
    }

    public function render(): View
    {
        return view('livewire.shared.chat-badge');
    }

    /** The member's profile; null before they have one (the badge then shows nothing live). */
    private function me(): ?Profile
    {
        $member = auth('web')->user();

        return $member instanceof User ? $member->profile : null;
    }
}
