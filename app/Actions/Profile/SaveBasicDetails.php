<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Actions\Profile\Concerns\GuardsWizardStep;
use App\Data\Profile\BasicDetailsData;
use App\Domain\Profile\ProfileRules;
use App\Enums\MaritalStatus;
use App\Models\HoroscopeDetail;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Wizard step 1 (M02): name, DOB (18 F / 21 M — MinimumMarriageAge), height, weight, marital
 * status, physical status, religion → caste (caste must belong to religion), mother tongue,
 * star, rasi, doshams. Profile columns + horoscope_details, in one transaction.
 * Children are always 0 for NEVER_MARRIED. Gender, DOB, religion and marital status are locked
 * after first publish, gender also when "profile for" implies it (R-M02-1, ProfileRules::lockedFields).
 */
final class SaveBasicDetails
{
    use GuardsWizardStep;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Profile $profile, BasicDetailsData $data, bool $partial = false): void
    {
        $partial = $this->isPartial($profile, $partial);
        $values = $this->authorizeAndValidate($actor, $profile, $data->toArray(), ProfileRules::basic($data->toArray(), $partial));

        $this->assertUnchanged($profile, $values, ProfileRules::lockedFields($profile));

        $neverMarried = ($values['marital_status'] ?? null) === MaritalStatus::NeverMarried->value;

        $this->persist($profile, function () use ($profile, $values, $neverMarried): void {
            $profile->forceFill([
                'first_name' => trim((string) $values['first_name']),
                'last_name' => self::trimOrNull($values['last_name'] ?? null),
                'gender' => $values['gender'],
                'dob' => $values['dob'] ?? null,
                'height_cm' => $values['height_cm'] ?? null,
                'weight_kg' => $values['weight_kg'] ?? null,
                'marital_status' => $values['marital_status'] ?? null,
                'children_count' => $neverMarried ? 0 : (int) ($values['children_count'] ?? 0),
                'physical_status' => $values['physical_status'],
                'religion_id' => $values['religion_id'] ?? null,
                'caste_id' => $values['caste_id'] ?? null,
                'caste_no_bar' => (bool) ($values['caste_no_bar'] ?? false),
                'sub_caste' => self::trimOrNull($values['sub_caste'] ?? null),
                'mother_tongue_id' => $values['mother_tongue_id'] ?? null,
                'star_id' => $values['star_id'] ?? null,
                'rasi_id' => $values['rasi_id'] ?? null,
            ])->save();

            $horoscope = HoroscopeDetail::query()->whereKey($profile->id)->first() ?? new HoroscopeDetail;
            $horoscope->forceFill([
                'profile_id' => $profile->id,
                'chovva_dosham' => $values['chovva_dosham'] ?? null,
                'papa_dosham' => $values['papa_dosham'] ?? null,
            ])->save();
        });
    }

    private static function trimOrNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
