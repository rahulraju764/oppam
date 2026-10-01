<?php

declare(strict_types=1);

namespace App\Livewire\Forms\Wizard;

use App\Data\Profile\ContactDetailsData;
use App\Domain\Profile\ProfileRules;
use App\Models\Profile;

/**
 * Wizard step 5 — contact (template contact-details.php). Rules: ProfileRules::contact. The
 * verified mobile is shown read-only by the view, never edited here. A first visit starts from
 * the account email and the permanent address of step 2.
 */
final class ContactForm extends WizardForm
{
    private const FIELDS = ['contact_email', 'alternate_phone', 'contact_person', 'contact_relation', 'convenient_time',
        'country_id', 'state_id', 'district_id', 'city', 'address_line'];

    public string $contact_email = '';

    public string $alternate_phone = '';

    public string $contact_person = '';

    public string $contact_relation = '';

    public string $convenient_time = '';

    public string $country_id = '';

    public string $state_id = '';

    public string $district_id = '';

    public string $city = '';

    public string $address_line = '';

    public function load(Profile $profile): void
    {
        $contact = $profile->contactDetail;

        if ($contact !== null) {
            foreach (self::FIELDS as $field) {
                $this->{$field} = self::toInput($contact->getAttribute($field));
            }

            return;
        }

        $career = $profile->educationCareer;
        $this->contact_email = self::toInput($profile->user?->email);
        $this->country_id = self::toInput($career?->permanent_country_id);
        $this->state_id = self::toInput($career?->permanent_state_id);
        $this->district_id = self::toInput($career?->permanent_district_id);
        $this->city = self::toInput($career?->permanent_city);
    }

    public function countryChanged(): void
    {
        $this->state_id = '';
        $this->district_id = '';
    }

    public function stateChanged(): void
    {
        $this->district_id = '';
    }

    /** @return array<string, list<mixed>> */
    protected function rules(): array
    {
        return ProfileRules::contact($this->toData()->toArray());
    }

    public function toData(): ContactDetailsData
    {
        return new ContactDetailsData(
            contact_email: self::str($this->contact_email),
            alternate_phone: self::str($this->alternate_phone),
            contact_person: self::str($this->contact_person),
            contact_relation: self::str($this->contact_relation),
            convenient_time: self::str($this->convenient_time),
            country_id: self::int($this->country_id),
            state_id: self::int($this->state_id),
            district_id: self::int($this->district_id),
            city: self::str($this->city),
            address_line: self::str($this->address_line),
        );
    }
}
