<?php

declare(strict_types=1);

namespace App\Livewire\Forms\Wizard;

use App\Data\Profile\FamilyDetailsData;
use App\Domain\Profile\ProfileRules;
use App\Models\Profile;

/** Wizard step 3 — family (template family.php). Rules: ProfileRules::family. */
final class FamilyForm extends WizardForm
{
    private const FIELDS = ['father_name', 'father_occupation', 'mother_name', 'mother_occupation', 'brothers_married',
        'brothers_unmarried', 'sisters_married', 'sisters_unmarried', 'family_status_option_id', 'family_type_option_id',
        'family_values_option_id', 'native_place', 'about_family'];

    public string $father_name = '';

    public string $father_occupation = '';

    public string $mother_name = '';

    public string $mother_occupation = '';

    public string $brothers_married = '0';

    public string $brothers_unmarried = '0';

    public string $sisters_married = '0';

    public string $sisters_unmarried = '0';

    public string $family_status_option_id = '';

    public string $family_type_option_id = '';

    public string $family_values_option_id = '';

    public string $native_place = '';

    public string $about_family = '';

    public function load(Profile $profile): void
    {
        $family = $profile->familyDetail;

        if ($family === null) {
            return;
        }

        foreach (self::FIELDS as $field) {
            $this->{$field} = self::toInput($family->getAttribute($field));
        }
    }

    /** @return array<string, list<mixed>> */
    protected function rules(): array
    {
        return ProfileRules::family();
    }

    public function toData(): FamilyDetailsData
    {
        return new FamilyDetailsData(
            father_name: self::str($this->father_name),
            father_occupation: self::str($this->father_occupation),
            mother_name: self::str($this->mother_name),
            mother_occupation: self::str($this->mother_occupation),
            brothers_married: self::int($this->brothers_married),
            brothers_unmarried: self::int($this->brothers_unmarried),
            sisters_married: self::int($this->sisters_married),
            sisters_unmarried: self::int($this->sisters_unmarried),
            family_status_option_id: self::int($this->family_status_option_id),
            family_type_option_id: self::int($this->family_type_option_id),
            family_values_option_id: self::int($this->family_values_option_id),
            native_place: self::str($this->native_place),
            about_family: self::str($this->about_family),
        );
    }
}
