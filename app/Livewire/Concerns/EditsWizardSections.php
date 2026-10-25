<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Profile\SaveAboutDetails;
use App\Actions\Profile\SaveBasicDetails;
use App\Actions\Profile\SaveCareerDetails;
use App\Actions\Profile\SaveContactDetails;
use App\Actions\Profile\SaveFamilyDetails;
use App\Actions\Profile\SavePartnerPreferences;
use App\Domain\Profile\PendingTextEdits;
use App\Enums\MaritalStatus;
use App\Enums\WizardStep;
use App\Livewire\Forms\Wizard\AboutForm;
use App\Livewire\Forms\Wizard\BasicForm;
use App\Livewire\Forms\Wizard\CareerForm;
use App\Livewire\Forms\Wizard\ContactForm;
use App\Livewire\Forms\Wizard\FamilyForm;
use App\Livewire\Forms\Wizard\PreferenceForm;
use App\Models\Profile;
use App\Models\User;
use App\Services\Masters\Masters;
use Illuminate\Validation\ValidationException;

/**
 * The M02 profile sections as Livewire state — one Form Object per wizard step — shared by the
 * member wizard (/onboarding/{step}) and the bureau's managed-profile form (§11A B.6, P7.3), so
 * both validate with ProfileRules and save through the same step Actions (which authorize the
 * actor on the profile). Dependent lists reset with their parent (caste ← religion, district ←
 * state ← country).
 */
trait EditsWizardSections
{
    public BasicForm $basic;

    public CareerForm $career;

    public FamilyForm $family;

    public PreferenceForm $preference;

    public ContactForm $contact;

    public AboutForm $about;

    private function loadSections(Profile $profile): void
    {
        foreach ([$this->basic, $this->career, $this->family, $this->preference, $this->contact, $this->about] as $form) {
            $form->load($profile);
        }

        // A live profile's held text edits (R-M02-4) are what was last typed: show those.
        $pending = app(PendingTextEdits::class)->pending($profile);

        $this->basic->first_name = $pending['profiles.first_name'] ?? $this->basic->first_name;
        $this->basic->last_name = $pending['profiles.last_name'] ?? $this->basic->last_name;
        $this->about->about = $pending['profiles.about'] ?? $this->about->about;
        $this->family->about_family = $pending['family_details.about_family'] ?? $this->family->about_family;
        $this->preference->about_partner = $pending['partner_preferences.about_partner'] ?? $this->preference->about_partner;
    }

    public function updated(string $property, mixed $value): void
    {
        match ($property) {
            'basic.religion_id' => $this->basic->caste_id = '',
            'basic.marital_status' => $value === MaritalStatus::NeverMarried->value ? $this->basic->children_count = '0' : null,
            'career.current_country_id' => [$this->career->current_state_id, $this->career->current_district_id] = ['', ''],
            'career.current_state_id' => $this->career->current_district_id = '',
            'career.permanent_country_id' => [$this->career->permanent_state_id, $this->career->permanent_district_id] = ['', ''],
            'career.permanent_state_id' => $this->career->permanent_district_id = '',
            'contact.country_id' => $this->contact->countryChanged(),
            'contact.state_id' => $this->contact->stateChanged(),
            default => null,
        };
    }

    /** Partner-preference option buttons (multi-select). The list name is allow-listed by the form. */
    public function toggleChoice(string $list, string $value, Masters $masters): void
    {
        $this->preference->toggle($list, $value);
        $this->preference->keepCastesOfReligions($masters);
    }

    public function clearChoice(string $list): void
    {
        $this->preference->clear($list);

        if ($list === 'religion_ids') {
            $this->preference->clear('caste_ids');
        }
    }

    /**
     * Save one section. A full save validates the form first (errors under the fields), then the
     * step's Action validates again and authorizes the actor; a partial save keeps what is valid.
     *
     * @throws ValidationException
     */
    private function saveSection(User $actor, Profile $profile, WizardStep $step, bool $partial): void
    {
        if (! $partial) {
            match ($step) {
                WizardStep::Basic => $this->basic->validate(),
                WizardStep::Career => $this->career->validate(),
                WizardStep::Family => $this->family->validate(),
                WizardStep::Preferences => $this->preference->validate($this->preference->rulesFor($profile->gender)),
                WizardStep::Contact => $this->contact->validate(),
                WizardStep::Photos => $this->about->validate(),
            };
        }

        match ($step) {
            WizardStep::Basic => app(SaveBasicDetails::class)->handle($actor, $profile, $this->basic->toData(), $partial),
            WizardStep::Career => app(SaveCareerDetails::class)->handle($actor, $profile, $this->career->toData(), $partial),
            WizardStep::Family => app(SaveFamilyDetails::class)->handle($actor, $profile, $this->family->toData(), $partial),
            WizardStep::Preferences => app(SavePartnerPreferences::class)->handle($actor, $profile, $this->preference->toData(), $partial),
            WizardStep::Contact => app(SaveContactDetails::class)->handle($actor, $profile, $this->contact->toData(), $partial),
            WizardStep::Photos => app(SaveAboutDetails::class)->handle($actor, $profile, $this->about->toData(), $partial),
        };
    }
}
