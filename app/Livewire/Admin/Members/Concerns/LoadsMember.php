<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Members\Concerns;

use App\Enums\UserRole;
use App\Models\AdminUser;
use App\Models\Profile;
use App\Models\User;
use Livewire\Attributes\Locked;

/**
 * The member a detail page / tab is about, addressed by profile code (URLs use codes, never
 * ULIDs). Deleted members are included (recovery). Unknown code → 404.
 */
trait LoadsMember
{
    #[Locked]
    public string $code = '';

    private ?User $loadedMember = null;

    protected function member(): User
    {
        if ($this->loadedMember !== null) {
            return $this->loadedMember;
        }

        $profile = Profile::withTrashed()->where('code', $this->code)->first() ?? abort(404);
        // Members only: a profile owned by any other account is not an A03 page.
        $member = User::withTrashed()->whereKey($profile->user_id)->where('role', UserRole::Member->value)->first() ?? abort(404);
        $member->setRelation('profile', $profile);

        return $this->loadedMember = $member;
    }

    protected function profile(): Profile
    {
        /** @var Profile */
        return $this->member()->profile;
    }

    protected function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
