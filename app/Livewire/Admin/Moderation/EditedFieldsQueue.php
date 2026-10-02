<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Moderation;

use App\Actions\Moderation\DecideProfileEdit;
use App\Domain\Moderation\ProfileEditDiff;
use App\Enums\ModerationItemType;
use App\Enums\RejectReason;
use App\Exceptions\Moderation\ModerationItemUnavailable;
use App\Models\AdminUser;
use App\Models\ModerationItem;
use App\Queries\Moderation\ModerationQueue;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A04 edited-fields queue (R-M02-4): text changes to live profiles, old next to new, with the
 * text pre-flags. Approve applies the new text; reject keeps the old one and tells the member.
 *
 * @property-read LengthAwarePaginator<int, ModerationItem> $items
 */
#[Layout('layouts::admin', ['title' => 'Edited fields'])]
final class EditedFieldsQueue extends Component
{
    use WithPagination;

    public string $reason = 'CONTACT_IN_TEXT';

    public string $note = '';

    public function mount(): void
    {
        Gate::authorize('moderation.view');
    }

    /** @return LengthAwarePaginator<int, ModerationItem> */
    #[Computed]
    public function items(): LengthAwarePaginator
    {
        return app(ModerationQueue::class)->open(ModerationItemType::ProfileEdit)
            ->with(['profile:id,code,first_name,last_name,about', 'profile.familyDetail', 'profile.partnerPreference', 'claimedBy:id,name'])
            ->paginate(20);
    }

    public function approve(string $itemId, string $seen, DecideProfileEdit $decide): void
    {
        $this->decide($itemId, $seen, true, $decide);
    }

    public function reject(string $itemId, string $seen, DecideProfileEdit $decide): void
    {
        $this->decide($itemId, $seen, false, $decide);
    }

    public function render(ProfileEditDiff $diff): View
    {
        $rows = [];

        foreach ($this->items->items() as $item) {
            $rows[(string) $item->id] = $diff->rows($item);
        }

        return view('livewire.admin.moderation.edited-fields-queue', [
            'rows' => $rows,
            'reasons' => RejectReason::options(),
            'canDecide' => $this->admin()->can('moderation.act'),
        ]);
    }

    /** $seen = fingerprint of the text shown on screen (the Action refuses if it has changed). */
    private function decide(string $itemId, string $seen, bool $approve, DecideProfileEdit $decide): void
    {
        $item = ModerationItem::query()->ofType(ModerationItemType::ProfileEdit)->pending()->whereKey($itemId)->first() ?? abort(404);

        try {
            $decide->handle($this->admin(), $item, $seen, $approve, RejectReason::tryFrom($this->reason), $this->note);
            $this->dispatch('toast', type: 'success', message: $approve ? __('Changes approved.') : __('Changes rejected.'));
            $this->note = '';
        } catch (ModerationItemUnavailable $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());
        }

        unset($this->items);
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
