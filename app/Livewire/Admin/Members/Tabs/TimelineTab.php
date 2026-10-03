<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Members\Tabs;

use App\Livewire\Admin\Members\Concerns\IsMemberTab;
use App\Models\AuditLog;
use App\Models\ModerationItem;
use App\Models\Subscription;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A03 "Timeline (A12)": audit rows about this member — the account, the profile, its moderation
 * items and its subscriptions — newest first. Each subject is looked up with the
 * (subject_type, subject_id, created_at) index.
 */
final class TimelineTab extends Component
{
    use IsMemberTab;
    use WithPagination;

    public function mount(string $code): void
    {
        $this->openTab($code);
    }

    public function render(): View
    {
        $member = $this->member();
        $profile = $this->profile();
        $subjects = [
            'User' => [$member->id],
            'Profile' => [$profile->id],
            'ModerationItem' => ModerationItem::query()->where('profile_id', $profile->id)->latest('submitted_at')->limit(200)->pluck('id')->all(),
            'Subscription' => Subscription::query()->where('profile_id', $profile->id)->limit(200)->pluck('id')->all(),
        ];

        $entries = AuditLog::query()
            ->where(function (Builder $query) use ($subjects): void {
                foreach ($subjects as $type => $ids) {
                    if ($ids !== []) {
                        $query->orWhere(fn (Builder $q) => $q->where('subject_type', $type)->whereIn('subject_id', $ids));
                    }
                }
            })
            ->latest('created_at')->orderByDesc('id')
            ->paginate(25, pageName: 'timeline');

        return view('livewire.admin.members.tabs.timeline-tab', [
            'entries' => $entries,
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }
}
