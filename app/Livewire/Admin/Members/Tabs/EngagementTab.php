<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Members\Tabs;

use App\Enums\AccessRequestStatus;
use App\Enums\InterestStatus;
use App\Livewire\Admin\Members\Concerns\IsMemberTab;
use App\Models\AccessRequest;
use App\Models\Favorite;
use App\Models\Interest;
use App\Models\Like;
use App\Models\Profile;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A03 "Engagement" tab (carried from P1.7a, built with P3.5): how the member uses likes and
 * interests — counts, and the latest interests in either direction with the other member's code
 * and the status. Favourites appear only as a NUMBER: R-M06-6 "admins see them only in
 * aggregate" — never whom, never the notes. Interest notes are not shown either. Read-only.
 */
final class EngagementTab extends Component
{
    use IsMemberTab;
    use WithPagination;

    public function mount(string $code): void
    {
        $this->openTab($code);
    }

    public function render(): View
    {
        $profile = Profile::withTrashed()->where('user_id', $this->member()->id)->first();

        return view('livewire.admin.members.tabs.engagement-tab', [
            'hasProfile' => $profile !== null,
            'counts' => $profile !== null ? $this->counts($profile) : [],
            'interests' => $profile !== null ? $this->interests($profile) : null,
            'timezone' => (string) config('oppam.display_timezone'),
            'profileId' => $profile?->id,
        ]);
    }

    /** @return array<string, int> */
    private function counts(Profile $profile): array
    {
        $sent = Interest::query()->where('from_profile_id', $profile->id)->selectRaw('status, count(*) as total')->groupBy('status')->toBase()->pluck('total', 'status');
        $received = Interest::query()->where('to_profile_id', $profile->id)->selectRaw('status, count(*) as total')->groupBy('status')->toBase()->pluck('total', 'status');
        $accepted = InterestStatus::Accepted->value;

        return [
            'likesGiven' => Like::query()->where('from_profile_id', $profile->id)->count(),
            'likesReceived' => Like::query()->where('to_profile_id', $profile->id)->count(),
            'favorites' => Favorite::query()->where('profile_id', $profile->id)->count(),
            'interestsSent' => (int) $sent->sum(),
            'interestsReceived' => (int) $received->sum(),
            'connections' => (int) ($sent[$accepted] ?? 0) + (int) ($received[$accepted] ?? 0),
            'pendingReceived' => (int) ($received[InterestStatus::Pending->value] ?? 0),
            'requestsWaiting' => AccessRequest::query()->where('owner_profile_id', $profile->id)->where('status', AccessRequestStatus::Pending->value)->count(),
        ];
    }

    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Interest> */
    private function interests(Profile $profile): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        // The other member's code in the same query (no lookup per row).
        return Interest::query()
            ->where(fn ($q) => $q->where('interests.from_profile_id', $profile->id)->orWhere('interests.to_profile_id', $profile->id))
            ->join('profiles as other', fn ($join) => $join->whereRaw(
                'other.id = case when interests.from_profile_id = ? then interests.to_profile_id else interests.from_profile_id end', [$profile->id]))
            ->select(['interests.*', 'other.code as other_code'])
            ->orderByDesc('interests.sent_at')->orderByDesc('interests.id')
            ->paginate(15, pageName: 'engagement');
    }
}
