<?php

declare(strict_types=1);

namespace App\Domain\Profile;

use App\Data\Profile\ProfileCardData;
use App\Domain\Engagement\FavoritePrivacy;
use App\Domain\Media\PhotoUrls;
use App\Models\Profile;
use App\Models\User;
use App\Queries\Engagement\EngagementLists;
use App\Services\Masters\Masters;
use App\ValueObjects\HeightCm;

/**
 * A profile as a list card for one viewer (x-profile.member-card / .tile / .row): masked name,
 * the primary photo through PhotoUrls (blurred when the viewer may not see it clearly; a neutral
 * placeholder when none is visible), and codes — never a ULID or contact detail.
 */
final class ProfileCards
{
    /** "Newly joined" ribbon: published within this many days (M04 "newly joined (7 days)"). */
    public const NEW_DAYS = 7;

    public function __construct(
        private readonly PhotoUrls $photos,
        private readonly ProfileNames $names,
        private readonly Masters $masters,
        private readonly EngagementLists $engagement,
        private readonly FavoritePrivacy $favoritePrivacy,
    ) {}

    public function forViewer(Profile $profile, ?User $viewer): ProfileCardData
    {
        $district = $profile->district_id !== null ? $this->districtLabel($profile->district_id) : null;

        return new ProfileCardData(
            code: $profile->code,
            name: $this->names->forViewer($profile, $viewer),
            photoUrl: $this->photos->primaryCardUrl($profile, $viewer) ?? PhotoUrls::PLACEHOLDER,
            url: route('member.profile.show', ['profile' => $profile->code]),
            age: $profile->age() !== null ? __(':age yrs', ['age' => $profile->age()]) : null,
            height: $profile->height_cm !== null ? HeightCm::of($profile->height_cm)->label() : null,
            place: $district,
        );
    }

    /**
     * forViewer() for a whole page of profiles (search, lists): photo URLs in one batch
     * (PhotoUrls::primaryCardUrls), district labels from the cached master list, the viewer's
     * likes / bookmarks in two — no query per card. Pass profiles with privacySetting loaded.
     *
     * @param  iterable<Profile>  $profiles
     * @return list<ProfileCardData>
     */
    public function forViewers(iterable $profiles, User $viewer, bool $withEngagement = true): array
    {
        $profiles = collect($profiles);
        $urls = $this->photos->primaryCardUrls($profiles, $viewer);
        $districts = Masters::forSelect($this->masters->allDistricts());
        $newSince = now()->subDays(self::NEW_DAYS);
        // The viewer's own likes / bookmarks for the whole page in two queries (M06 buttons).
        // Skipped (no buttons) for cards that show none, e.g. the 6-up member-card grid.
        $own = $viewer->profile;
        $state = $withEngagement && $own !== null
            ? $this->engagement->stateFor($own, $profiles->map(fn (Profile $p): string => (string) $p->id)->all())
            : null;
        // R-M06-6: an admin support session never learns whom the member bookmarked — no star at all.
        $showFavorites = $state !== null && ! $this->favoritePrivacy->hiddenInThisSession();

        return $profiles->map(fn (Profile $profile): ProfileCardData => new ProfileCardData(
            code: $profile->code,
            name: $this->names->forViewer($profile, $viewer),
            photoUrl: $urls[(string) $profile->id] ?? PhotoUrls::PLACEHOLDER,
            url: route('member.profile.show', ['profile' => $profile->code]),
            age: $profile->age() !== null ? __(':age yrs', ['age' => $profile->age()]) : null,
            height: $profile->height_cm !== null ? HeightCm::of($profile->height_cm)->label() : null,
            place: $profile->district_id !== null ? ($districts[$profile->district_id] ?? null) : null,
            isNew: $profile->published_at?->greaterThan($newSince) === true,
            liked: $state !== null ? isset($state['liked'][(string) $profile->id]) : null,
            favorited: $showFavorites ? isset($state['favorited'][(string) $profile->id]) : null,
        ))->values()->all();
    }

    private function districtLabel(int $id): ?string
    {
        foreach ($this->masters->countries() as $country) {
            foreach ($this->masters->statesForCountry($country->id) as $state) {
                $label = Masters::forSelect($this->masters->districtsForState($state->id))[$id] ?? null;
                if ($label !== null) {
                    return $label;
                }
            }
        }

        return null;
    }
}
