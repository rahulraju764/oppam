<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Moderation;

use App\Actions\Moderation\DecidePhoto;
use App\Actions\Moderation\DecideProfileEdit;
use App\Domain\Moderation\ProfileEditDiff;
use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Enums\RejectReason;
use App\Exceptions\Moderation\ModerationItemUnavailable;
use App\Models\AdminUser;
use App\Models\Media;
use App\Models\ModerationItem;
use App\Queries\Moderation\ModerationQueue;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A04 escalations: items a moderator passed up, with their reason. Anyone with
 * `moderation.view` can look; only super admins decide (owner decision 2026-10-01). Profiles
 * open in the normal review page; photos and edited texts are decided here — an edit shows its
 * old and new text, and the decision carries the fingerprint of the text shown.
 *
 * @property-read LengthAwarePaginator<int, ModerationItem> $items
 */
#[Layout('layouts::admin', ['title' => 'Escalations'])]
final class Escalations extends Component
{
    use WithPagination;

    public string $reason = 'INAPPROPRIATE';

    public string $note = '';

    public function mount(): void
    {
        Gate::authorize('moderation.view');
    }

    /** @return LengthAwarePaginator<int, ModerationItem> */
    #[Computed]
    public function items(): LengthAwarePaginator
    {
        return app(ModerationQueue::class)->escalated()
            ->with(['profile:id,code,first_name,last_name,about', 'profile.familyDetail', 'profile.partnerPreference'])
            ->paginate(25);
    }

    public function approve(string $itemId, string $seen = ''): void
    {
        $this->decide($itemId, $seen, true);
    }

    public function reject(string $itemId, string $seen = ''): void
    {
        $this->decide($itemId, $seen, false);
    }

    public function render(ProfileEditDiff $diff): View
    {
        $items = collect($this->items->items());
        $edits = [];

        foreach ($items->where('type', ModerationItemType::ProfileEdit) as $item) {
            $edits[(string) $item->id] = $diff->rows($item);
        }

        return view('livewire.admin.moderation.escalations', [
            'photos' => Media::query()->whereIn('uuid', $items->where('type', ModerationItemType::Photo)->pluck('subject_id')->filter()->all())->get()->keyBy('uuid'),
            'edits' => $edits,
            'reasons' => RejectReason::options(),
            'canDecide' => $this->admin()->isSuperAdmin(),
        ]);
    }

    private function decide(string $itemId, string $seen, bool $approve): void
    {
        // Scoped lookup: only escalated items exist here (404 otherwise).
        $item = ModerationItem::query()->whereKey($itemId)->where('status', ModerationStatus::Escalated)->first() ?? abort(404);
        $reason = RejectReason::tryFrom($this->reason);

        try {
            match ($item->type) {
                ModerationItemType::Photo => app(DecidePhoto::class)->handle($this->admin(), $item, $approve, $reason, $this->note),
                ModerationItemType::ProfileEdit => app(DecideProfileEdit::class)->handle($this->admin(), $item, $seen, $approve, $reason, $this->note),
                ModerationItemType::ProfileNew => abort(404),   // decided on its review page
            };
            $this->dispatch('toast', type: 'success', message: __('Decision saved.'));
            $this->note = '';
        } catch (ModerationItemUnavailable|AuthorizationException $e) {
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
