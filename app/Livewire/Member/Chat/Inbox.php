<?php

declare(strict_types=1);

namespace App\Livewire\Member\Chat;

use App\Actions\Chat\SetConversationArchived;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Models\Profile;
use App\Models\User;
use App\Queries\Chat\InboxList;
use App\Services\Admin\Impersonation;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The conversation list (PRD M07 inbox, template messages.php left rail): newest first, the
 * member's unread count per thread, filters All / Unread / Archived, search by name, "show more"
 * (20 at a time), archive / unarchive. Live: `.conversation.updated` on the member's own
 * `inbox.{profile}` channel re-reads the list (re-orders, unread badges); so does a socket
 * reconnect (PRD §9.7). Rows come from InboxList (the member's own threads only, never a blocked
 * member). In an admin support session previews are hidden and archiving is refused.
 */
final class Inbox extends Component
{
    public const PAGE = 20;

    /** The code of the thread open beside the list (highlighted). */
    #[Locked]
    public string $current = '';

    public string $filter = InboxList::ALL;

    public string $search = '';

    #[Locked]
    public int $limit = self::PAGE;

    /** @return array<string, string> */
    public function getListeners(): array
    {
        if (app(Impersonation::class)->isActive()) {   // InboxChannel refuses a support session
            return ['chat-inbox-changed' => '$refresh'];
        }

        return [
            'echo-private:inbox.'.$this->me()->id.',.conversation.updated' => '$refresh',
            'realtime-reconnected' => '$refresh',
            'chat-inbox-changed' => '$refresh',   // the open thread sent / read something
        ];
    }

    public function show(string $filter): void
    {
        $this->filter = in_array($filter, InboxList::FILTERS, true) ? $filter : InboxList::ALL;
        $this->limit = self::PAGE;
    }

    public function updatedSearch(): void
    {
        $this->limit = self::PAGE;
    }

    public function showMore(): void
    {
        $this->limit = min($this->limit + self::PAGE, 500);
    }

    public function archive(string $code, SetConversationArchived $archive): void
    {
        $this->setArchived($code, true, $archive);
    }

    public function unarchive(string $code, SetConversationArchived $archive): void
    {
        $this->setArchived($code, false, $archive);
    }

    public function render(InboxList $inbox, Impersonation $impersonation): View
    {
        $this->filter = in_array($this->filter, InboxList::FILTERS, true) ? $this->filter : InboxList::ALL;
        $me = $this->me();
        $page = $inbox->page($this->member(), $me, $this->filter, $this->search, $this->limit, hidePreviews: $impersonation->isActive());

        return view('livewire.member.chat.inbox', [
            'items' => $page['items'],
            'more' => $page['more'],
            'unreadThreads' => $inbox->unreadThreads($me),
            'filters' => [InboxList::ALL => __('All'), InboxList::UNREAD => __('Unread'), InboxList::ARCHIVED => __('Archived')],
        ]);
    }

    private function setArchived(string $code, bool $archived, SetConversationArchived $archive): void
    {
        $other = Messages::validCode($code) !== '' ? Profile::withTrashed()->where('code', $code)->first() : null;

        try {
            $archive->handle($this->member(), $other ?? throw new NotFoundHttpException, $archived);
        } catch (ImpersonationRestricted $restricted) {
            $this->dispatch('toast', title: $restricted->getMessage(), type: 'danger');

            return;
        } catch (NotFoundHttpException) {
            $this->dispatch('toast', title: __('This is no longer available.'), type: 'danger');

            return;
        }

        $this->dispatch('toast', type: 'success', title: $archived ? __('Conversation archived.') : __('Conversation moved back to your inbox.'));
    }

    private function member(): User
    {
        /** @var User */
        return auth('web')->user();
    }

    private function me(): Profile
    {
        return $this->member()->profile ?? abort(404);
    }
}
