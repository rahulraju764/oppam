<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Actions\Profile\Concerns\GuardsWizardStep;
use App\Data\Profile\AboutDetailsData;
use App\Domain\Profile\PendingTextEdits;
use App\Domain\Profile\ProfileRules;
use App\Enums\PhotoVisibility;
use App\Models\LifestyleDetail;
use App\Models\PrivacySetting;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Wizard step 6, non-photo part (M02 + M11): about me (min 50 characters), lifestyle (diet,
 * smoking, drinking, hobbies) and who may see the photos. Photo upload itself is P1.4.
 */
final class SaveAboutDetails
{
    use GuardsWizardStep;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Profile $profile, AboutDetailsData $data, bool $partial = false): void
    {
        $partial = $this->isPartial($profile, $partial);
        $values = $this->authorizeAndValidate($actor, $profile, $data->toArray(), ProfileRules::about($partial));

        $about = is_string($values['about'] ?? null) ? trim($values['about']) : '';
        $hobbies = collect(is_array($values['hobbies'] ?? null) ? $values['hobbies'] : [])
            ->map(fn (mixed $hobby): string => trim((string) $hobby))
            ->filter(fn (string $hobby): bool => $hobby !== '')
            ->unique()->values()->all();

        $this->persist($profile, function () use ($profile, $values, $about, $hobbies): void {
            $profile->forceFill(app(PendingTextEdits::class)->hold($profile, 'profiles', ['about' => $about === '' ? null : $about], $profile->getAttributes()))->save();

            $lifestyle = LifestyleDetail::query()->whereKey($profile->id)->first() ?? new LifestyleDetail;
            $lifestyle->forceFill([
                'profile_id' => $profile->id,
                'diet_option_id' => $values['diet_option_id'] ?? null,
                'smoking_option_id' => $values['smoking_option_id'] ?? null,
                'drinking_option_id' => $values['drinking_option_id'] ?? null,
                'hobbies' => $hobbies,
            ])->save();

            $privacy = PrivacySetting::query()->whereKey($profile->id)->first() ?? new PrivacySetting;
            $privacy->forceFill([
                'profile_id' => $profile->id,
                'photo_visibility' => $values['photo_visibility'] ?? $privacy->photo_visibility ?? PhotoVisibility::AllMembers->value,
            ])->save();
        });
    }
}
