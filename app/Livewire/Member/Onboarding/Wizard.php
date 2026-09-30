<?php

declare(strict_types=1);

namespace App\Livewire\Member\Onboarding;

use App\Data\Content\SeoData;
use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Support\Navigation\MemberLanding;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * /onboarding/{step} — the 6-step profile wizard (M02). P1.1 ships only the landing point after
 * registration: the steps themselves (form objects, autosave, dependent selects) are built in
 * P1.2 / P1.3, which replace this body. Only a DRAFT profile can be here (R-M02-2).
 */
final class Wizard extends Component
{
    public const STEPS = 6;

    #[Locked]
    public int $step = 1;

    public function mount(int $step, MemberLanding $landing): mixed
    {
        $user = auth('web')->user();

        // Broker and staff logins own no matrimony profile (PRD §8.1).
        if (! $user instanceof User || $user->role !== UserRole::Member) {
            abort(404);
        }

        $status = $user->profile?->status;

        if ($status !== null && $status !== ProfileStatus::Draft) {
            return $this->redirect($landing->url($user), navigate: true);
        }

        $this->step = max(1, min(self::STEPS, $step));

        return null;
    }

    public function render(): View
    {
        $user = auth('web')->user();

        return view('livewire.member.onboarding.wizard', [
            'profile' => $user instanceof User ? $user->profile : null,
        ])->layout('layouts::member', ['seo' => SeoData::private('Create your profile | Oppam Matrimony')]);
    }
}
