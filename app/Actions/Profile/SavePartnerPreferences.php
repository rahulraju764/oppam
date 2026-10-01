<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Actions\Profile\Concerns\GuardsWizardStep;
use App\Data\Profile\PartnerPreferenceData;
use App\Domain\Profile\ProfileRules;
use App\Models\PartnerPreference;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Wizard step 4 (M02): the partner the member is looking for — age and height ranges and
 * multi-select lists (an empty list = "any"); castes must belong to the chosen religions.
 */
final class SavePartnerPreferences
{
    use GuardsWizardStep;

    private const LISTS = ['marital_statuses', 'physical_statuses', 'religion_ids', 'caste_ids', 'mother_tongue_ids',
        'star_ids', 'education_ids', 'occupation_ids', 'country_ids', 'district_ids', 'diet_option_ids'];

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Profile $profile, PartnerPreferenceData $data, bool $partial = false): void
    {
        $partial = $this->isPartial($profile, $partial);
        $values = $this->authorizeAndValidate($actor, $profile, $data->toArray(), ProfileRules::preferences($data->toArray(), $profile->gender, $partial));

        $attributes = [
            'age_min' => $values['age_min'] ?? null,
            'age_max' => $values['age_max'] ?? null,
            'height_min_cm' => $values['height_min_cm'] ?? null,
            'height_max_cm' => $values['height_max_cm'] ?? null,
            'min_income_band_id' => $values['min_income_band_id'] ?? null,
            'about_partner' => self::trimOrNull($values['about_partner'] ?? null),
        ];

        foreach (self::LISTS as $list) {
            $items = is_array($values[$list] ?? null) ? $values[$list] : [];
            $attributes[$list] = array_values(array_unique(str_ends_with($list, '_ids') ? array_map(intval(...), $items) : array_map(strval(...), $items)));
        }

        $this->persist($profile, function () use ($profile, $attributes): void {
            $preference = PartnerPreference::query()->whereKey($profile->id)->first() ?? new PartnerPreference;
            $preference->forceFill(['profile_id' => $profile->id, ...$attributes])->save();
        });
    }

    private static function trimOrNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
