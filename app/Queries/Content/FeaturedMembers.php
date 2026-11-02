<?php

declare(strict_types=1);

namespace App\Queries\Content;

use App\Data\Profile\ProfileCardData;
use App\Domain\Media\PhotoUrls;
use App\Domain\Safety\BlockList;
use App\Enums\PhotoVisibility;
use App\Enums\ProfileStatus;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Models\Profile;
use App\Models\User;
use App\Services\Masters\Masters;
use App\Support\Facades\Settings;
use Illuminate\Database\Eloquent\Builder;

/**
 * The home page's featured members (M12, owner 2026-11-01): ACTIVE, premium, verified, photos
 * visible to all members, not incognito, and opted in to the home page, with an approved photo.
 * Only first name, district and photo leave here: no code, no link. Not cached, so opting out or
 * being suspended applies on the next page load. A signed-in viewer never sees a blocked pair.
 * Empty below the A15 minimum, so a thin band doesn't single a few members out.
 */
final class FeaturedMembers
{
    private const BAND_SIZE = 6;

    public function __construct(
        private readonly PhotoUrls $photos,
        private readonly BlockList $blocks,
        private readonly Masters $masters,
    ) {}

    /** @return list<ProfileCardData> */
    public function forViewer(?User $viewer): array
    {
        $own = $viewer?->profile;
        $hidden = $own !== null ? [...$this->blocks->hiddenFrom($own), (string) $own->id] : [];

        $profiles = Profile::query()
            ->where('status', ProfileStatus::Active)
            ->where('is_premium', true)
            ->where('is_verified', true)
            ->whereHas('user', fn (Builder $user) => $user->where('status', UserStatus::Active))
            ->whereHas('privacySetting', fn (Builder $privacy) => $privacy
                ->where('show_on_home', true)
                ->where('incognito', false)
                ->where('photo_visibility', PhotoVisibility::AllMembers))
            ->when($hidden !== [], fn (Builder $query) => $query->whereNotIn('profiles.id', $hidden))
            ->inRandomOrder()
            ->limit(self::BAND_SIZE * 2)
            ->get();

        $urls = $this->photos->publicCardUrls($profiles);
        $districts = Masters::forSelect($this->masters->allDistricts());

        $cards = $profiles
            ->filter(fn (Profile $profile): bool => isset($urls[(string) $profile->id]))
            ->take(self::BAND_SIZE)
            ->map(fn (Profile $profile): ProfileCardData => new ProfileCardData(
                code: '',
                name: (string) $profile->first_name,
                photoUrl: $urls[(string) $profile->id],
                place: $profile->district_id !== null ? ($districts[$profile->district_id] ?? null) : null,
            ))
            ->values()
            ->all();

        return count($cards) >= Settings::int(SettingKey::ContentHomeMembersMin) ? $cards : [];
    }
}
