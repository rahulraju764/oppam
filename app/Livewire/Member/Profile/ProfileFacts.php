<?php

declare(strict_types=1);

namespace App\Livewire\Member\Profile;

use App\Domain\Profile\ProfileNames;
use App\Enums\Dosham;
use App\Enums\EmployerType;
use App\Models\Profile;
use App\Models\User;
use App\Services\Masters\Masters;
use App\ValueObjects\HeightCm;
use BackedEnum;

/**
 * The profile page's fact tables (M03, template single-profile.php "tb-basic-info"): label →
 * value rows per section, with master ids turned into labels from the cached Masters lists.
 * Presentation only. Contact details are NOT here — they are revealed through ViewContact.
 * Empty values are dropped so a table never shows a row of dashes.
 */
final class ProfileFacts
{
    public function __construct(
        private readonly Masters $masters,
        private readonly ProfileNames $names,
    ) {}

    /** @return array<string, array<string, string>> section title => [label => value] */
    public function sections(Profile $profile, ?User $viewer): array
    {
        $career = $profile->educationCareer;
        $family = $profile->familyDetail;
        $lifestyle = $profile->lifestyleDetail;
        $horoscope = $profile->horoscopeDetail;

        return array_filter([
            __('Basic Details') => $this->rows([
                __('Name') => $this->names->forViewer($profile, $viewer),
                __('Age') => $profile->age() !== null ? __(':age years', ['age' => $profile->age()]) : null,
                __('Height') => $profile->height_cm !== null ? HeightCm::of($profile->height_cm)->label() : null,
                __('Weight') => $profile->weight_kg !== null ? __(':kg kg', ['kg' => $profile->weight_kg]) : null,
                __('Marital Status') => $profile->marital_status?->label(),
                __('Children') => $profile->children_count > 0 ? (string) $profile->children_count : null,
                __('Physical Status') => $profile->physical_status->label(),
                __('Mother Tongue') => $this->label($this->masters->motherTongues(), $profile->mother_tongue_id),
                __('Native District') => $this->label($this->allDistricts(), $profile->district_id),
            ]),
            __('Religious Information') => $this->rows([
                __('Religion') => $this->label($this->masters->religions(), $profile->religion_id),
                __('Caste') => $profile->religion_id !== null ? $this->label($this->masters->castesForReligion($profile->religion_id), $profile->caste_id) : null,
                __('Sub-caste') => $this->text($profile->getAttribute('sub_caste')),
                __('Caste no bar') => $profile->caste_no_bar ? __('Yes') : null,
                __('Star') => $this->label($this->masters->stars(), $profile->star_id),
                __('Rasi') => $this->label($this->masters->rasis(), $profile->rasi_id),
                __('Chovva Dosham') => $this->enum($horoscope?->getAttribute('chovva_dosham')),
                __('Papa Dosham') => $this->enum($horoscope?->getAttribute('papa_dosham')),
            ]),
            __('Professional Information') => $this->rows([
                __('Education') => $this->label($this->masters->education(), $this->int($career?->getAttribute('education_id'))),
                __('Education Details') => $this->text($career?->getAttribute('education_detail')),
                __('Occupation') => $this->label($this->masters->occupations(), $this->int($career?->getAttribute('occupation_id'))),
                __('Employed In') => $this->enum($career?->getAttribute('employer_type')),
                __('Annual Income') => $this->label($this->masters->incomeBands(), $this->int($career?->getAttribute('income_band_id'))),
                __('Lives In') => $this->place($this->int($career?->getAttribute('current_country_id')), $this->int($career?->getAttribute('current_district_id')), $this->text($career?->getAttribute('current_city'))),
            ]),
            __('Family Details') => $this->rows([
                __('Father') => $this->joined($this->text($family?->getAttribute('father_name')), $this->text($family?->getAttribute('father_occupation'))),
                __('Mother') => $this->joined($this->text($family?->getAttribute('mother_name')), $this->text($family?->getAttribute('mother_occupation'))),
                __('Brothers') => $this->siblings($this->int($family?->getAttribute('brothers_married')), $this->int($family?->getAttribute('brothers_unmarried'))),
                __('Sisters') => $this->siblings($this->int($family?->getAttribute('sisters_married')), $this->int($family?->getAttribute('sisters_unmarried'))),
                __('Family Status') => $this->label($this->masters->options('family_status'), $this->int($family?->getAttribute('family_status_option_id'))),
                __('Family Type') => $this->label($this->masters->options('family_type'), $this->int($family?->getAttribute('family_type_option_id'))),
                __('Family Values') => $this->label($this->masters->options('family_values'), $this->int($family?->getAttribute('family_values_option_id'))),
                __('Native Place') => $this->text($family?->getAttribute('native_place')),
            ]),
            __('Lifestyle') => $this->rows([
                __('Diet') => $this->label($this->masters->options('diet'), $this->int($lifestyle?->getAttribute('diet_option_id'))),
                __('Smoking') => $this->label($this->masters->options('smoking'), $this->int($lifestyle?->getAttribute('smoking_option_id'))),
                __('Drinking') => $this->label($this->masters->options('drinking'), $this->int($lifestyle?->getAttribute('drinking_option_id'))),
                __('Hobbies') => $lifestyle?->hobbies !== null && $lifestyle->hobbies !== [] ? implode(', ', $lifestyle->hobbies) : null,
            ]),
        ], fn (array $rows): bool => $rows !== []);
    }

    /**
     * The short facts under the name (template .profile-list): age + height, education + job, place.
     *
     * @return array<string, string>
     */
    public function headline(Profile $profile): array
    {
        $career = $profile->educationCareer;

        return array_filter([
            'age' => $profile->age() !== null ? __(':age yrs', ['age' => $profile->age()]) : null,
            'height' => $profile->height_cm !== null ? HeightCm::of($profile->height_cm)->label() : null,
            'education' => $this->label($this->masters->education(), $this->int($career?->getAttribute('education_id'))),
            'occupation' => $this->label($this->masters->occupations(), $this->int($career?->getAttribute('occupation_id'))),
            'place' => $this->label($this->allDistricts(), $profile->district_id),
        ]);
    }

    /**
     * @param  array<string, string|null>  $rows
     * @return array<string, string>
     */
    private function rows(array $rows): array
    {
        return array_filter($rows, fn (?string $value): bool => $value !== null && $value !== '');
    }

    /** @param  list<\App\Data\Masters\MasterItem>  $items */
    private function label(array $items, ?int $id): ?string
    {
        return $id === null ? null : (Masters::forSelect($items)[$id] ?? null);
    }

    private function place(?int $countryId, ?int $districtId, ?string $city): ?string
    {
        $parts = array_filter([$city, $this->label($this->allDistricts(), $districtId), $this->label($this->masters->countries(), $countryId)]);

        return $parts === [] ? null : implode(', ', array_unique($parts));
    }

    private function siblings(?int $married, ?int $unmarried): ?string
    {
        $married ??= 0;
        $unmarried ??= 0;

        if ($married + $unmarried === 0) {
            return null;
        }

        return __(':total (:married married)', ['total' => $married + $unmarried, 'married' => $married]);
    }

    private function joined(?string $name, ?string $occupation): ?string
    {
        return $name === null ? null : ($occupation === null ? $name : $name.' — '.$occupation);
    }

    private function enum(mixed $value): ?string
    {
        return $value instanceof Dosham || $value instanceof EmployerType ? $value->label() : ($value instanceof BackedEnum ? (string) $value->value : null);
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /** @return list<\App\Data\Masters\MasterItem> */
    private function allDistricts(): array
    {
        $districts = [];
        foreach ($this->masters->countries() as $country) {
            foreach ($this->masters->statesForCountry($country->id) as $state) {
                array_push($districts, ...$this->masters->districtsForState($state->id));
            }
        }

        return $districts;
    }
}
