<?php

declare(strict_types=1);

namespace App\Livewire\Member\Onboarding;

use App\Data\Content\SeoData;
use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Support\Navigation\MemberLanding;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * /onboarding/submitted — "your profile is under review" (R-M02-2). Members wait here while
 * their profile is PENDING_REVIEW; anyone else is sent to their landing page. The dashboard
 * takes over this message in P2.3.
 */
final class Submitted extends Component
{
    public function mount(MemberLanding $landing): mixed
    {
        $user = auth('web')->user();

        if (! $user instanceof User || $user->role !== UserRole::Member || $user->profile === null) {
            abort(404);
        }

        if ($user->profile->status !== ProfileStatus::PendingReview) {
            return $this->redirect($landing->url($user), navigate: true);
        }

        return null;
    }

    public function render(): View
    {
        /** @var User $user */
        $user = auth('web')->user();

        return view('livewire.member.onboarding.submitted', [
            'profile' => $user->profile,
        ])->layout('layouts::member', ['seo' => SeoData::private('Profile submitted | Oppam Matrimony')]);
    }
}
