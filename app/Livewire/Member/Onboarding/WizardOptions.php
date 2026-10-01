<?php

declare(strict_types=1);

namespace App\Livewire\Member\Onboarding;

use App\Domain\Profile\ProfileRules;
use App\Enums\Dosham;
use App\Enums\EmployerType;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\PhotoVisibility;
use App\Enums\PhysicalStatus;
use App\Enums\WizardStep;
use App\Livewire\Forms\Wizard\BasicForm;
use App\Livewire\Forms\Wizard\CareerForm;
use App\Livewire\Forms\Wizard\ContactForm;
use App\Livewire\Forms\Wizard\PreferenceForm;
use App\Services\Masters\Masters;
use App\ValueObjects\HeightCm;

/**
 * The option lists each wizard step's view needs (M02), built from the cached Masters service
 * and enums. Only the current step's lists are built; dependent lists (castes, states,
 * districts) follow the form's current choice. Presentation only — no rules here.
 */
final class WizardOptions
{
    public function __construct(private readonly Masters $masters) {}

    /** @return array<string, array<int|string, string>> */
    public function forStep(WizardStep $step, Gender $gender, BasicForm $basic, CareerForm $career, PreferenceForm $preference, ContactForm $contact): array
    {
        return match ($step) {
            WizardStep::Basic => [
                'heights' => HeightCm::options(),
                'maritalStatuses' => MaritalStatus::options(),
                'physicalStatuses' => PhysicalStatus::options(),
                'doshams' => Dosham::options(),
                'religions' => $this->list($this->masters->religions()),
                'castes' => is_numeric($basic->religion_id) ? $this->list($this->masters->castesForReligion((int) $basic->religion_id)) : [],
                'motherTongues' => $this->list($this->masters->motherTongues()),
                'stars' => $this->list($this->masters->stars()),
                'rasis' => $this->list($this->masters->rasis()),
            ],
            WizardStep::Career => [
                'employerTypes' => EmployerType::options(),
                'education' => $this->list($this->masters->education()),
                'occupations' => $this->list($this->masters->occupations()),
                'incomeBands' => $this->list($this->masters->incomeBands()),
                'countries' => $this->list($this->masters->countries()),
                'currentStates' => $this->states($career->current_country_id),
                'currentDistricts' => $this->districts($career->current_state_id),
                'permanentStates' => $this->states($career->permanent_country_id),
                'permanentDistricts' => $this->districts($career->permanent_state_id),
            ],
            WizardStep::Family => [
                'familyStatuses' => $this->list($this->masters->options('family_status')),
                'familyTypes' => $this->list($this->masters->options('family_type')),
                'familyValues' => $this->list($this->masters->options('family_values')),
            ],
            WizardStep::Preferences => [
                'ages' => array_combine($ages = array_map(strval(...), range(ProfileRules::partnerMinimumAge($gender), ProfileRules::PARTNER_AGE_MAX)), $ages),
                'heights' => HeightCm::options(),
                'maritalStatuses' => MaritalStatus::options(),
                'physicalStatuses' => PhysicalStatus::options(),
                'religions' => $this->list($this->masters->religions()),
                'castes' => $this->castesFor($preference->religion_ids),
                'motherTongues' => $this->list($this->masters->motherTongues()),
                'stars' => $this->list($this->masters->stars()),
                'education' => $this->list($this->masters->education()),
                'occupations' => $this->list($this->masters->occupations()),
                'incomeBands' => $this->list($this->masters->incomeBands()),
                'countries' => $this->list($this->masters->countries()),
                'districts' => $this->allDistricts(),
                'diets' => $this->list($this->masters->options('diet')),
            ],
            WizardStep::Contact => [
                'countries' => $this->list($this->masters->countries()),
                'states' => $this->states($contact->country_id),
                'districts' => $this->districts($contact->state_id),
            ],
            WizardStep::Photos => [
                'diets' => $this->list($this->masters->options('diet')),
                'smoking' => $this->list($this->masters->options('smoking')),
                'drinking' => $this->list($this->masters->options('drinking')),
                'photoVisibilities' => PhotoVisibility::options(),
            ],
        };
    }

    /**
     * @param  list<\App\Data\Masters\MasterItem>  $items
     * @return array<int, string>
     */
    private function list(array $items): array
    {
        return Masters::forSelect($items);
    }

    /** @return array<int, string> */
    private function states(string $countryId): array
    {
        return is_numeric($countryId) ? $this->list($this->masters->statesForCountry((int) $countryId)) : [];
    }

    /** @return array<int, string> */
    private function districts(string $stateId): array
    {
        return is_numeric($stateId) ? $this->list($this->masters->districtsForState((int) $stateId)) : [];
    }

    /**
     * Castes of every chosen religion, labelled with the religion when more than one is chosen.
     *
     * @param  list<string>  $religionIds
     * @return array<int, string>
     */
    private function castesFor(array $religionIds): array
    {
        $religions = $this->list($this->masters->religions());
        $castes = [];

        // A tampered list can't make render loop over thousands of ids: the rule allows 20.
        foreach (array_slice($religionIds, 0, 20) as $religionId) {
            foreach ($this->masters->castesForReligion((int) $religionId) as $caste) {
                $castes[$caste->id] = count($religionIds) > 1
                    ? $caste->label.' ('.($religions[(int) $religionId] ?? '').')'
                    : $caste->label;
            }
        }

        return $castes;
    }

    /**
     * Partner districts: every district in master data (Kerala today), across states.
     *
     * @return array<int, string>
     */
    private function allDistricts(): array
    {
        $districts = [];

        foreach ($this->masters->countries() as $country) {
            foreach ($this->masters->statesForCountry($country->id) as $state) {
                $districts += $this->list($this->masters->districtsForState($state->id));
            }
        }

        return $districts;
    }
}
