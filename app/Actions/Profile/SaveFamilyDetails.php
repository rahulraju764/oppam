<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Actions\Profile\Concerns\GuardsWizardStep;
use App\Data\Profile\FamilyDetailsData;
use App\Domain\Profile\PendingTextEdits;
use App\Domain\Profile\ProfileRules;
use App\Models\FamilyDetail;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/** Wizard step 3 (M02): parents, siblings (married / unmarried), family status, type, values. */
final class SaveFamilyDetails
{
    use GuardsWizardStep;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Profile $profile, FamilyDetailsData $data, bool $partial = false): void
    {
        $partial = $this->isPartial($profile, $partial);
        $values = $this->authorizeAndValidate($actor, $profile, $data->toArray(), ProfileRules::family($partial));

        $attributes = [];
        foreach (array_keys(ProfileRules::family(true)) as $field) {
            $value = $values[$field] ?? null;
            $attributes[$field] = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
        }

        foreach (['brothers_married', 'brothers_unmarried', 'sisters_married', 'sisters_unmarried'] as $count) {
            $attributes[$count] = (int) ($attributes[$count] ?? 0);
        }

        $this->persist($profile, function () use ($profile, $attributes): void {
            $family = FamilyDetail::query()->whereKey($profile->id)->first() ?? new FamilyDetail;
            $attributes = app(PendingTextEdits::class)->hold($profile, 'family_details', $attributes, $family->getAttributes());
            $family->forceFill(['profile_id' => $profile->id, ...$attributes])->save();
        });
    }
}
