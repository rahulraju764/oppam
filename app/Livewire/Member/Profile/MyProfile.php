<?php

declare(strict_types=1);

namespace App\Livewire\Member\Profile;

use App\Data\Content\SeoData;
use App\Domain\Media\PhotoUrls;
use App\Domain\Profile\CompletenessCalculator;
use App\Domain\Profile\PendingTextEdits;
use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Models\ModerationItem;
use App\Models\Profile;
use App\Models\ProfileView;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * /me — the member's own profile (M03, template my-profile.php): header with Edit / Photos /
 * Preview, completeness meter with "add X" links to the exact wizard step, a status banner
 * (under review / rejected with reason / hidden / suspended), views this week, and the profile's
 * sections exactly as saved. Likes and interests stats arrive with P3.3 / P3.4.
 */
final class MyProfile extends Component
{
    public function render(
        CompletenessCalculator $completeness,
        ProfileFacts $facts,
        PhotoUrls $photos,
        PendingTextEdits $pending,
        EntitlementService $entitlements,
    ): View {
        $user = $this->member();
        $profile = $user->profile ?? abort(404);
        $profile->load(['educationCareer', 'familyDetail', 'lifestyleDetail', 'horoscopeDetail', 'privacySetting', 'partnerPreference']);

        $weekStart = now((string) config('oppam.display_timezone'))->subDays(6)->toDateString();

        return view('livewire.member.profile.my-profile', [
            'profile' => $profile,
            'photo' => $photos->forViewer($profile, $user)[0] ?? null,
            'percent' => $completeness->percent($profile),
            'missing' => $completeness->missing($profile),
            'headline' => $facts->headline($profile),
            'sections' => $facts->sections($profile, $user),
            'pendingText' => $profile->status === ProfileStatus::Active ? $pending->pending($profile) : [],
            'rejectionNote' => $profile->status === ProfileStatus::Rejected ? ModerationItem::latestDecisionNote($profile) : null,
            'fixStep' => ModerationItem::latestDecisionStep($profile),
            'viewsThisWeek' => (int) ProfileView::query()->where('viewed_profile_id', $profile->id)->where('view_date', '>=', $weekStart)->count(),
            'plan' => $entitlements->plan($profile),
            'canEdit' => $user->can('editWizard', $profile),
        ])->layout('layouts::member', ['seo' => SeoData::private('My Profile | Oppam Matrimony')]);
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
