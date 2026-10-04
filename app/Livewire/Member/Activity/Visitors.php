<?php

declare(strict_types=1);

namespace App\Livewire\Member\Activity;

use App\Data\Content\SeoData;
use App\Domain\Activity\ProfileVisitors;
use App\Domain\Profile\ProfileCards;
use App\Enums\Entitlement;
use App\Models\Profile;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Visitors (M15, /visitors): "Who viewed my profile" — distinct visible members of the last 90
 * days with their last visit, for plans with see_who_viewed_me (Gold / Diamond); others see only
 * the number, a data-free blurred teaser and an upgrade link — and "Profiles I viewed" (all plans,
 * 90 days). Both read through ProfileVisitors, so blocked, ignored, incognito and non-ACTIVE
 * members are never listed nor counted. The live ".profile.viewed" update comes with P3.1.
 */
final class Visitors extends Component
{
    use WithPagination;

    public const PER_PAGE = 20;

    #[Url]
    public string $tab = 'visitors';

    public function show(string $tab): void
    {
        $this->tab = $tab === 'viewed' ? 'viewed' : 'visitors';
        $this->resetPage();
    }

    public function render(ProfileVisitors $visitors, ProfileCards $cards, EntitlementService $entitlements): View
    {
        $member = $this->member();
        $me = $member->profile ?? abort(404);
        $tab = $this->tab === 'viewed' ? 'viewed' : 'visitors';
        $unlocked = $entitlements->allows($me, Entitlement::SeeWhoViewedMe);
        $locked = $tab === 'visitors' && ! $unlocked;

        $page = $locked ? null : ($tab === 'viewed' ? $visitors->viewedBy($me) : $visitors->query($me))
            ->with('privacySetting')
            ->paginate(self::PER_PAGE);

        $rows = [];
        if ($page !== null) {
            $profiles = collect($page->items());
            $cardData = $cards->forViewers($profiles, $member);
            foreach ($profiles->values() as $i => $profile) {
                /** @var Profile $profile */
                $rows[] = ['card' => $cardData[$i], 'when' => $this->when($profile->getAttribute('last_viewed_at'))];
            }
        }

        return view('livewire.member.activity.visitors', [
            'tab' => $tab,
            'locked' => $locked,
            'rows' => $rows,
            'page' => $page,
            'visitorCount' => $visitors->count($me),
            'viewedCount' => $visitors->viewedBy($me)->count(),
        ])->layout('layouts::member', [
            'seo' => SeoData::private('Visitors | Oppam Matrimony'),
        ]);
    }

    private function when(mixed $at): string
    {
        return is_string($at) || $at instanceof DateTimeInterface
            ? CarbonImmutable::parse($at)->setTimezone((string) config('oppam.display_timezone'))->diffForHumans()
            : '';
    }

    private function member(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
