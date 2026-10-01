<?php

declare(strict_types=1);

namespace App\Livewire\Forms\Wizard;

use App\Data\Profile\BasicDetailsData;
use App\Domain\Profile\ProfileRules;
use App\Enums\PhysicalStatus;
use App\Models\Profile;

/** Wizard step 1 — basic details (template profile-creation.php). Rules: ProfileRules::basic. */
final class BasicForm extends WizardForm
{
    public string $first_name = '';

    public string $last_name = '';

    public string $gender = '';

    public string $dob = '';

    public string $height_cm = '';

    public string $weight_kg = '';

    public string $marital_status = '';

    public string $children_count = '0';

    public string $physical_status = PhysicalStatus::Normal->value;

    public string $religion_id = '';

    public string $caste_id = '';

    public bool $caste_no_bar = false;

    public string $sub_caste = '';

    public string $mother_tongue_id = '';

    public string $star_id = '';

    public string $rasi_id = '';

    public string $chovva_dosham = '';

    public string $papa_dosham = '';

    public function load(Profile $profile): void
    {
        foreach (['first_name', 'last_name', 'gender', 'dob', 'height_cm', 'weight_kg', 'marital_status', 'children_count',
            'physical_status', 'religion_id', 'caste_id', 'sub_caste', 'mother_tongue_id', 'star_id', 'rasi_id'] as $field) {
            $this->{$field} = self::toInput($profile->getAttribute($field));
        }

        $this->caste_no_bar = $profile->caste_no_bar;
        $this->chovva_dosham = self::toInput($profile->horoscopeDetail?->chovva_dosham);
        $this->papa_dosham = self::toInput($profile->horoscopeDetail?->papa_dosham);
    }

    /** @return array<string, list<mixed>> */
    protected function rules(): array
    {
        return ProfileRules::basic($this->toData()->toArray());
    }

    public function toData(): BasicDetailsData
    {
        return new BasicDetailsData(
            first_name: self::str($this->first_name),
            last_name: self::str($this->last_name),
            gender: self::str($this->gender),
            dob: self::str($this->dob),
            height_cm: self::int($this->height_cm),
            weight_kg: self::int($this->weight_kg),
            marital_status: self::str($this->marital_status),
            children_count: self::int($this->children_count),
            physical_status: self::str($this->physical_status),
            religion_id: self::int($this->religion_id),
            caste_id: self::int($this->caste_id),
            caste_no_bar: $this->caste_no_bar,
            sub_caste: self::str($this->sub_caste),
            mother_tongue_id: self::int($this->mother_tongue_id),
            star_id: self::int($this->star_id),
            rasi_id: self::int($this->rasi_id),
            chovva_dosham: self::str($this->chovva_dosham),
            papa_dosham: self::str($this->papa_dosham),
        );
    }
}
