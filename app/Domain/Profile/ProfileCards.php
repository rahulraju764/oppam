<?php

declare(strict_types=1);

namespace App\Domain\Profile;

use App\Data\Profile\ProfileCardData;
use App\Domain\Media\PhotoUrls;
use App\Models\Profile;
use App\Models\User;
use App\Services\Masters\Masters;
use App\ValueObjects\HeightCm;

/**
 * A profile as a list card for one viewer (x-profile.member-card / .tile / .row): masked name,
 * the primary photo through PhotoUrls (blurred when the viewer may not see it clearly; a neutral
 * placeholder when none is visible), and codes — never a ULID or contact detail.
 */
final class ProfileCards
{
    public function __construct(
        private readonly PhotoUrls $photos,
        private readonly ProfileNames $names,
        private readonly Masters $masters,
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
