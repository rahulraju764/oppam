<?php

declare(strict_types=1);

namespace App\Livewire\Forms\Wizard;

use App\Data\Profile\CareerDetailsData;
use App\Domain\Profile\ProfileRules;
use App\Models\Profile;

/** Wizard step 2 — education & career (template education.php). Rules: ProfileRules::career. */
final class CareerForm extends WizardForm
{
    private const FIELDS = ['education_id', 'education_detail', 'employer_type', 'occupation_id', 'employer_name', 'income_band_id',
        'current_country_id', 'current_state_id', 'current_district_id', 'current_city', 'citizenship', 'visa_status',
        'permanent_country_id', 'permanent_state_id', 'permanent_district_id', 'permanent_city'];

    public string $education_id = '';

    public string $education_detail = '';

    public string $employer_type = '';

    public string $occupation_id = '';

    public string $employer_name = '';

    public string $income_band_id = '';

    public string $current_country_id = '';

    public string $current_state_id = '';

    public string $current_district_id = '';

    public string $current_city = '';

    public string $citizenship = '';

    public string $visa_status = '';

    public string $permanent_country_id = '';

    public string $permanent_state_id = '';

    public string $permanent_district_id = '';

    public string $permanent_city = '';

    public function load(Profile $profile): void
    {
        $career = $profile->educationCareer;

        foreach (self::FIELDS as $field) {
            $this->{$field} = self::toInput($career?->getAttribute($field));
        }
    }

    /** @return array<string, list<mixed>> */
    protected function rules(): array
    {
        return ProfileRules::career($this->toData()->toArray());
    }

    public function toData(): CareerDetailsData
    {
        return new CareerDetailsData(
            education_id: self::int($this->education_id),
            education_detail: self::str($this->education_detail),
            employer_type: self::str($this->employer_type),
            occupation_id: self::int($this->occupation_id),
            employer_name: self::str($this->employer_name),
            income_band_id: self::int($this->income_band_id),
            current_country_id: self::int($this->current_country_id),
            current_state_id: self::int($this->current_state_id),
            current_district_id: self::int($this->current_district_id),
            current_city: self::str($this->current_city),
            citizenship: self::str($this->citizenship),
            visa_status: self::str($this->visa_status),
            permanent_country_id: self::int($this->permanent_country_id),
            permanent_state_id: self::int($this->permanent_state_id),
            permanent_district_id: self::int($this->permanent_district_id),
            permanent_city: self::str($this->permanent_city),
        );
    }
}
