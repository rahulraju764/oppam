<?php

declare(strict_types=1);

namespace App\Queries\Admin;

use App\Models\Profile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * A07 "Unverified codes" (B.12): self-registered members whose typed / `?ref=` code matched no
 * ACTIVE bureau (R-M13-1 — kept raw, never auto-linked). Only members who proved their mobile:
 * an unproved sign-up is released later and isn't anyone's work. Oldest first.
 */
final class UnverifiedReferralCodes
{
    /** @return LengthAwarePaginator<int, Profile> */
    public function page(int $perPage = 25): LengthAwarePaginator
    {
        return $this->query()
            ->select(['id', 'code', 'broker_code_raw', 'status', 'created_at'])
            ->orderBy('created_at')->orderBy('id')
            ->paginate($perPage);
    }

    public function count(): int
    {
        return $this->query()->count();
    }

    public function find(string $profileCode): ?Profile
    {
        return $this->query()->where('code', $profileCode)->first();
    }

    /** @return Builder<Profile> */
    private function query(): Builder
    {
        return Profile::query()
            ->where('broker_verified', false)
            ->whereNotNull('broker_code_raw')
            ->whereNull('broker_id')
            ->whereHas('user', fn (Builder $user) => $user->whereNotNull('phone_verified_at'));
    }
}
