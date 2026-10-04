<?php

declare(strict_types=1);

namespace App\Livewire\Member\Activity;

use App\Data\Content\SeoData;
use App\Data\Profile\ProfileCardData;
use App\Domain\Profile\ProfileCards;
use App\Enums\Entitlement;
use App\Enums\UserRole;
use App\Models\Profile;
use App\Models\ProfileView;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Visitors & Profile Views page (PRD §10 M15).
 * - "Who viewed my profile": Gold/Diamond see full viewer history with timestamps;
 *   Free/Silver see visitor count + blurred teaser + upgrade CTA.
 * - "Profiles I viewed": 90-day history for all members.
 */
#[Layout('layouts::member')]
final class Visitors extends Component
{
    use WithPagination;

    #[Url(as: 'tab')]
    public string $tab = 'who_viewed_me';

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['who_viewed_me', 'viewed_by_me'], true)) {
            $this->tab = $tab;
            $this->resetPage();
        }
    }

    public function render(
        EntitlementService $entitlements,
        ProfileCards $cardsService
    ): View {
        $user = $this->member();
        /** @var Profile $profile */
        $profile = $user->profile;

        $canSeeVisitors = $entitlements->allows($profile, Entitlement::SeeWhoViewedMe);

        $ninetyDaysAgo = CarbonImmutable::now()->subDays(90);

        // Counts
        $whoViewedCount = ProfileView::query()
            ->where('viewed_profile_id', $profile->id)
            ->where('updated_at', '>=', $ninetyDaysAgo)
            ->count();

        $viewedByMeCount = ProfileView::query()
            ->where('viewer_profile_id', $profile->id)
            ->where('updated_at', '>=', $ninetyDaysAgo)
            ->count();

        /** @var list<array{view: ProfileView, card: ProfileCardData}> $items */
        $items = [];
        /** @var LengthAwarePaginator<int, ProfileView>|null $paginator */
        $paginator = null;

        if ($this->tab === 'who_viewed_me') {
            if ($canSeeVisitors) {
                $paginator = ProfileView::query()
                    ->with([
                        'viewer' => function ($q): void {
                            $q->with(['media', 'educationCareer', 'privacySetting']);
                        },
                    ])
                    ->where('viewed_profile_id', $profile->id)
                    ->where('updated_at', '>=', $ninetyDaysAgo)
                    ->orderByDesc('updated_at')
                    ->paginate(15);

                foreach ($paginator->items() as $view) {
                    if ($view->viewer !== null) {
                        $items[] = [
                            'view' => $view,
                            'card' => $cardsService->forViewer($view->viewer, $user),
                        ];
                    }
                }
            }
        } else {
            $paginator = ProfileView::query()
                ->with([
                    'viewed' => function ($q): void {
                        $q->with(['media', 'educationCareer', 'privacySetting']);
                    },
                ])
                ->where('viewer_profile_id', $profile->id)
                ->where('updated_at', '>=', $ninetyDaysAgo)
                ->orderByDesc('updated_at')
                ->paginate(15);

            foreach ($paginator->items() as $view) {
                if ($view->viewed !== null) {
                    $items[] = [
                        'view' => $view,
                        'card' => $cardsService->forViewer($view->viewed, $user),
                    ];
                }
            }
        }

        return view('livewire.member.activity.visitors', [
            'canSeeVisitors' => $canSeeVisitors,
            'whoViewedCount' => $whoViewedCount,
            'viewedByMeCount' => $viewedByMeCount,
            'items' => $items,
            'paginator' => $paginator,
            'profile' => $profile,
        ])->layoutData([
            'seo' => SeoData::private(__('Visitors | Oppam Matrimony')),
        ]);
    }

    private function member(): User
    {
        $user = auth('web')->user();

        if (! $user instanceof User || $user->role !== UserRole::Member || ! $user->profile instanceof Profile) {
            abort(404);
        }

        return $user;
    }
}
