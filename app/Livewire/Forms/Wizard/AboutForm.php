<?php

declare(strict_types=1);

namespace App\Livewire\Forms\Wizard;

use App\Data\Profile\AboutDetailsData;
use App\Domain\Profile\ProfileRules;
use App\Enums\PhotoVisibility;
use App\Models\Profile;
use Closure;

/**
 * Wizard step 6, non-photo part (template profile-photos.php + ➕about me, lifestyle). Rules:
 * ProfileRules::about. Hobbies are typed comma-separated. Photos arrive in P1.4.
 */
final class AboutForm extends WizardForm
{
    public string $about = '';

    public string $diet_option_id = '';

    public string $smoking_option_id = '';

    public string $drinking_option_id = '';

    public string $hobbies = '';

    public string $photo_visibility = PhotoVisibility::AllMembers->value;

    public function load(Profile $profile): void
    {
        $this->about = self::toInput($profile->getAttribute('about'));

        $lifestyle = $profile->lifestyleDetail;
        if ($lifestyle !== null) {
            $this->diet_option_id = self::toInput($lifestyle->diet_option_id);
            $this->smoking_option_id = self::toInput($lifestyle->smoking_option_id);
            $this->drinking_option_id = self::toInput($lifestyle->drinking_option_id);
            $this->hobbies = implode(', ', $lifestyle->hobbies ?? []);
        }

        $visibility = $profile->privacySetting?->photo_visibility;
        if ($visibility !== null) {
            $this->photo_visibility = self::toInput($visibility);
        }
    }

    /**
     * ProfileRules::about, except hobbies: the form holds them as comma-separated text, so the text
     * is checked here with the same limits (10 hobbies, 40 characters each) the Action applies to
     * the list.
     *
     * @return array<string, list<mixed>>
     */
    protected function rules(): array
    {
        $rules = ProfileRules::about();
        unset($rules['hobbies.*']);
        $rules['hobbies'] = ['nullable', 'string', 'max:450', function (string $attribute, mixed $value, Closure $fail): void {
            $hobbies = $this->toData()->hobbies;

            if (count($hobbies) > ProfileRules::HOBBIES_MAX) {
                $fail(__('Add up to :max hobbies.', ['max' => ProfileRules::HOBBIES_MAX]));
            } elseif (array_filter($hobbies, fn (string $hobby): bool => mb_strlen($hobby) > ProfileRules::HOBBY_MAX_LENGTH) !== []) {
                $fail(__('Keep each hobby under :max characters.', ['max' => ProfileRules::HOBBY_MAX_LENGTH]));
            }
        }];

        return $rules;
    }

    public function toData(): AboutDetailsData
    {
        $hobbies = array_values(array_filter(array_map(trim(...), explode(',', $this->hobbies)), fn (string $hobby): bool => $hobby !== ''));

        return new AboutDetailsData(
            about: self::str($this->about),
            diet_option_id: self::int($this->diet_option_id),
            smoking_option_id: self::int($this->smoking_option_id),
            drinking_option_id: self::int($this->drinking_option_id),
            hobbies: $hobbies,
            photo_visibility: self::str($this->photo_visibility),
        );
    }
}
