<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Moderation;

use App\Enums\ModerationItemType;
use App\Models\ModerationItem;
use App\Queries\Moderation\ModerationQueue;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A04 profile queue: submitted profiles waiting for review — paid lane first, oldest first —
 * with who is reviewing each right now. Opening one claims it (ProfileReview).
 *
 * @property-read LengthAwarePaginator<int, ModerationItem> $items
 */
#[Layout('layouts::admin', ['title' => 'Profile queue'])]
final class ProfileQueue extends Component
{
    use WithPagination;

    #[Url(as: 'lane')]
    public bool $priorityOnly = false;

    public function mount(): void
    {
        Gate::authorize('moderation.view');
    }

    /** @return LengthAwarePaginator<int, ModerationItem> */
    #[Computed]
    public function items(): LengthAwarePaginator
    {
        return app(ModerationQueue::class)
            ->open(ModerationItemType::ProfileNew, $this->priorityOnly)
            ->with(['profile:id,code,first_name,last_name,gender,dob,is_premium', 'claimedBy:id,name'])
            ->paginate(25);
    }

    public function updatedPriorityOnly(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.admin.moderation.profile-queue');
    }
}
