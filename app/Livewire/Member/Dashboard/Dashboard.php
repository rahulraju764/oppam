<?php

declare(strict_types=1);

namespace App\Livewire\Member\Dashboard;

use App\Data\Content\SeoData;
use App\Domain\Media\PhotoUrls;
use App\Enums\PlanCode;
use App\Models\Profile;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Router;
use Livewire\Component;

/**
 * Member dashboard (M05, template dashboard.php, 3/6/3): left rail = own profile card (photo,
 * name, code, plan, completeness), upgrade box for Free members and the member menu (only pages
 * that exist yet); centre = the sliders, each a #[Lazy] Strip; right = the ad rail. The page
 * itself runs no match query — the strips load after it. "Liked you" arrives with likes (P3.3),
 * live counters with real-time (P3.1).
 */
final class Dashboard extends Component
{
    public function render(PhotoUrls $photos, EntitlementService $entitlements, Router $router): View
    {
        $member = $this->member();
        $profile = $member->profile ?? abort(404);
        $plan = $entitlements->plan($profile);

        return view('livewire.member.dashboard.dashboard', [
            'profile' => $profile,
            'planLabel' => $plan->name,
            'isFree' => $plan->code === PlanCode::Free,
            'photoUrl' => $photos->primaryCardUrl($profile, $member) ?? PhotoUrls::PLACEHOLDER,
            'menu' => array_values(array_filter($this->menu(), fn (array $item): bool => $router->has($item[0]))),
            'strips' => Strip::KINDS,
        ])->layout('layouts::member', [
            'seo' => SeoData::private('Dashboard | Oppam Matrimony'),
        ]);
    }

    /** @return list<array{0: string, 1: string, 2: string, 3?: array<string, string>, 4?: string}> route, label, icon, params, fragment */
    private function menu(): array
    {
        return [
            ['member.profile.me', __('My Profile'), 'fa-user-o'],
            ['member.profile.me', __('Partner Preferences'), 'fa-sliders', [], 'partner-preferences'],
            ['member.matches', __('My Matches'), 'fa-heart-o'],
            ['member.profiles', __('All Profiles'), 'fa-users'],
            ['member.matches.daily', __('Daily Matches'), 'fa-bolt'],
            ['member.likes', __('Likes'), 'fa-thumbs-o-up'],
            ['member.favorites', __('Favorites'), 'fa-star-o'],
            ['member.interests', __('Interests'), 'fa-paper-plane-o'],
            ['member.messages', __('Messages'), 'fa-envelope-o'],
            ['member.visitors', __('Visitors'), 'fa-eye'],
            ['member.verify', __('Verify Profile'), 'fa-check-circle-o'],
            ['member.settings', __('Settings'), 'fa-cog'],
        ];
    }

    private function member(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
