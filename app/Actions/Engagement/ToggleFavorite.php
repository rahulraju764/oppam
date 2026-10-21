<?php

declare(strict_types=1);

namespace App\Actions\Engagement;

use App\Domain\Engagement\EngagementRestrictions;
use App\Domain\Engagement\EngagementSignals;
use App\Domain\Matching\DailyBatch;
use App\Domain\Profile\ProfileVisibility;
use App\Enums\EngagementSignalType;
use App\Enums\Entitlement;
use App\Enums\ProfileStatus;
use App\Enums\SettingKey;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Billing\QuotaExceeded;
use App\Exceptions\Engagement\EngagementRefused;
use App\Models\DailyMatch;
use App\Models\Favorite;
use App\Models\Profile;
use App\Models\User;
use App\Services\Admin\Impersonation;
use App\Services\Entitlements\EntitlementService;
use App\Support\Facades\Settings;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Add or remove a private bookmark (PRD M06). The caller says which state it wants, so a repeat
 * changes nothing (R-M06-1). Adding needs an ACTIVE own profile, a target the member may see
 * (else 404) and room under the plan's total (EntitlementService, §7.3) — the row and the count
 * move together in one transaction. Removing touches only the member's own row and gives the slot
 * back. Nobody is ever told: no event, no notification (R-M06-6).
 */
final class ToggleFavorite
{
    public function __construct(
        private readonly ProfileVisibility $visibility,
        private readonly EntitlementService $entitlements,
        private readonly RateLimiter $limiter,
        private readonly Impersonation $impersonation,
        private readonly EngagementRestrictions $restrictions,
        private readonly EngagementSignals $signals,
    ) {}

    /**
     * @return bool whether the profile is a favourite now
     *
     * @throws NotFoundHttpException the member may not see this profile (or it is their own)
     * @throws EngagementRefused own profile not ACTIVE, or too many changes in a minute
     * @throws QuotaExceeded the plan's favourites are full
     * @throws ImpersonationRestricted an admin impersonating the member
     */
    public function handle(User $member, Profile $target, bool $favorite): bool
    {
        // The member's own private list: an admin looking around changes nothing in it.
        $this->impersonation->assertAllowed();

        $own = $member->profile;
        if ($own === null || $own->id === $target->id) {
            throw new NotFoundHttpException;
        }

        $key = 'favorites:'.$own->id;
        if ($this->limiter->tooManyAttempts($key, Settings::int(SettingKey::FavoritesMaxPerMinute))) {
            // A13 "mass favourites via automation": a member who keeps hitting the limit is a signal.
            $this->signals->raise($own->id, EngagementSignalType::FavoriteSpam,
                ['changes_per_minute' => Settings::int(SettingKey::FavoritesMaxPerMinute)]);

            throw EngagementRefused::tooFast();
        }
        $this->limiter->hit($key, 60);

        return $favorite ? $this->add($member, $own, $target) : $this->remove($own, $target);
    }

    private function add(User $member, Profile $own, Profile $target): bool
    {
        if (! $this->visibility->canView($target, $member)) {
            throw new NotFoundHttpException;
        }

        if ($own->status !== ProfileStatus::Active) {
            throw EngagementRefused::profileNotActive();
        }

        $this->restrictions->assertCanSend($own);

        DB::transaction(function () use ($own, $target): void {
            $inserted = Favorite::query()->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'profile_id' => $own->id,
                'favorite_profile_id' => $target->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 1) {
                $this->entitlements->consume($own, Entitlement::FavoritesTotal);   // QuotaExceeded rolls the row back
            }
        });

        DailyMatch::query()->where('profile_id', $own->id)->where('matched_profile_id', $target->id)
            ->where('match_date', DailyBatch::today())->update(['is_interacted' => true, 'updated_at' => now()]);

        return true;
    }

    private function remove(Profile $own, Profile $target): bool
    {
        DB::transaction(function () use ($own, $target): void {
            $removed = Favorite::query()->where('profile_id', $own->id)->where('favorite_profile_id', $target->id)->delete();

            if ($removed > 0) {
                $this->entitlements->release($own, Entitlement::FavoritesTotal);
            }
        });

        return false;
    }
}
