<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Actions\Profile\Concerns\GuardsWizardStep;
use App\Data\Profile\CareerDetailsData;
use App\Domain\Profile\ProfileRules;
use App\Models\EducationCareer;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Wizard step 2 (M02): education, employment, income band, current and permanent location
 * (country → state → district must chain). Also sets profiles.district_id, the district used by
 * search and "near me": the permanent (native) district when known, else the current one — an
 * NRI in Dubai is still matched by their Kerala district (docs/decisions.md 2026-09-30).
 */
final class SaveCareerDetails
{
    use GuardsWizardStep;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Profile $profile, CareerDetailsData $data, bool $partial = false): void
    {
        $partial = $this->isPartial($profile, $partial);
        $values = $this->authorizeAndValidate($actor, $profile, $data->toArray(), ProfileRules::career($data->toArray(), $partial));

        $attributes = [];
        foreach (array_keys(ProfileRules::career([], true)) as $field) {
            $value = $values[$field] ?? null;
            $attributes[$field] = is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value);
        }

        DB::transaction(function () use ($profile, $attributes): void {
            $career = EducationCareer::query()->whereKey($profile->id)->first() ?? new EducationCareer;
            $career->forceFill(['profile_id' => $profile->id, ...$attributes])->save();

            $profile->forceFill([
                'district_id' => $attributes['permanent_district_id'] ?? $attributes['current_district_id'] ?? null,
            ])->save();
        });
    }
}
