<?php

declare(strict_types=1);

namespace App\Livewire\Member\Onboarding;

use App\Actions\Profile\SaveBasicDetails;
use App\Actions\Profile\SaveCareerDetails;
use App\Actions\Profile\SaveFamilyDetails;
use App\Data\Content\SeoData;
use App\Domain\Profile\WizardProgress;
use App\Enums\Dosham;
use App\Enums\EmployerType;
use App\Enums\MaritalStatus;
use App\Enums\PhysicalStatus;
use App\Enums\UserRole;
use App\Enums\WizardStep;
use App\Livewire\Forms\Wizard\BasicForm;
use App\Livewire\Forms\Wizard\CareerForm;
use App\Livewire\Forms\Wizard\FamilyForm;
use App\Models\User;
use App\Services\Masters\Masters;
use App\Support\Navigation\MemberLanding;
use App\ValueObjects\HeightCm;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * /onboarding/{step} — the profile wizard (M02, template profile-creation.php … family.php).
 * One component, one Form Object per step, each step saved by its own Action (which authorizes
 * and re-validates). Saves on "Continue" and autosaves after 20 s without typing (Alpine →
 * autosave(), partial rules). Religion → caste and country → state → district are reactive.
 * The profile is always the signed-in member's own — never taken from the request.
 * Steps 4–6 arrive in P1.3 / P1.4.
 */
final class Wizard extends Component
{
    private const AUTOSAVES_PER_MINUTE = 30;

    #[Locked]
    public int $step = 1;

    public BasicForm $basic;

    public CareerForm $career;

    public FamilyForm $family;

    /** Last successful autosave (IST "h:i A"), shown as "Saved at …". */
    public ?string $savedAt = null;

    public function mount(int $step, MemberLanding $landing): mixed
    {
        $user = $this->member();
        $profile = $user->profile;

        // No profile (e.g. soft-deleted): the landing would send the member straight back here.
        if ($profile === null) {
            abort(404);
        }

        if (! $user->can('editWizard', $profile)) {
            return $this->redirect($landing->url($user), navigate: true);
        }

        $requested = WizardStep::tryFrom(max(1, min(WizardStep::Photos->value, $step))) ?? WizardStep::Basic;
        $progress = new WizardProgress($profile);

        // Steps open in order: a later step can't be deep-linked before the earlier ones are done.
        if (! $progress->canOpen($requested)) {
            return $this->redirectRoute('member.onboarding', ['step' => $progress->firstIncomplete()->value], navigate: true);
        }

        $this->step = $requested->value;
        $this->basic->load($profile);
        $this->career->load($profile);
        $this->family->load($profile);

        return null;
    }

    // ---- Dependent selects ----------------------------------------------------------------------

    public function updatedBasicReligionId(): void
    {
        $this->basic->caste_id = '';
    }

    public function updatedBasicMaritalStatus(string $value): void
    {
        if ($value === MaritalStatus::NeverMarried->value) {
            $this->basic->children_count = '0';
        }
    }

    public function updatedCareerCurrentCountryId(): void
    {
        $this->career->current_state_id = '';
        $this->career->current_district_id = '';
    }

    public function updatedCareerCurrentStateId(): void
    {
        $this->career->current_district_id = '';
    }

    public function updatedCareerPermanentCountryId(): void
    {
        $this->career->permanent_state_id = '';
        $this->career->permanent_district_id = '';
    }

    public function updatedCareerPermanentStateId(): void
    {
        $this->career->permanent_district_id = '';
    }

    // ---- Saving -------------------------------------------------------------------------------

    /** "Continue": full validation, save, go to the next step. */
    public function next(): mixed
    {
        $this->save(partial: false);

        $next = WizardStep::from($this->step)->next() ?? WizardStep::Photos;

        return $this->redirectRoute('member.onboarding', ['step' => $next->value], navigate: true);
    }

    /** "Back": keep what is valid so far (like autosave), then go to the previous step. */
    public function previous(): mixed
    {
        try {
            $this->save(partial: true);   // not through autosave(): Back must not be skipped by its cap
        } catch (ValidationException) {
            // Invalid values just aren't kept, as with autosave.
        }

        $previous = WizardStep::from($this->step)->previous() ?? WizardStep::Basic;

        return $this->redirectRoute('member.onboarding', ['step' => $previous->value], navigate: true);
    }

    /**
     * Idle autosave: keeps whatever is valid so far; silent when something isn't valid yet.
     * Capped per member (AUTOSAVES_PER_MINUTE) so a scripted loop can't turn it into a write flood.
     */
    public function autosave(): void
    {
        $key = 'wizard-autosave:'.$this->member()->id;

        if (RateLimiter::tooManyAttempts($key, self::AUTOSAVES_PER_MINUTE)) {
            return;
        }

        RateLimiter::hit($key, 60);

        try {
            $this->save(partial: true);
        } catch (ValidationException) {
            return;
        }

        $this->savedAt = now()->timezone((string) config('oppam.display_timezone'))->format('g:i A');
    }

    private function save(bool $partial): void
    {
        $user = $this->member();
        $profile = $user->profile ?? abort(404);

        match (WizardStep::from($this->step)) {
            WizardStep::Basic => $this->saveStep($partial, $this->basic, fn () => app(SaveBasicDetails::class)->handle($user, $profile, $this->basic->toData(), $partial)),
            WizardStep::Career => $this->saveStep($partial, $this->career, fn () => app(SaveCareerDetails::class)->handle($user, $profile, $this->career->toData(), $partial)),
            WizardStep::Family => $this->saveStep($partial, $this->family, fn () => app(SaveFamilyDetails::class)->handle($user, $profile, $this->family->toData(), $partial)),
            default => null,   // steps 4–6: P1.3 / P1.4
        };
    }

    /** Full saves validate the form first so errors appear under the fields. */
    private function saveStep(bool $partial, BasicForm|CareerForm|FamilyForm $form, Closure $action): void
    {
        if (! $partial) {
            $form->validate();
        }

        $action();
    }

    // ---- View ---------------------------------------------------------------------------------

    public function render(Masters $masters): View
    {
        $profile = $this->member()->profile;
        $progress = $profile !== null ? new WizardProgress($profile) : null;
        $religionId = is_numeric($this->basic->religion_id) ? (int) $this->basic->religion_id : null;

        return view('livewire.member.onboarding.wizard', [
            'current' => WizardStep::from($this->step),
            'steps' => WizardStep::cases(),
            'progress' => $progress,
            'profile' => $profile,
            'heights' => HeightCm::options(),
            'maritalStatuses' => MaritalStatus::options(),
            'physicalStatuses' => PhysicalStatus::options(),
            'doshams' => Dosham::options(),
            'employerTypes' => EmployerType::options(),
            'religions' => Masters::forSelect($masters->religions()),
            'castes' => $religionId !== null ? Masters::forSelect($masters->castesForReligion($religionId)) : [],
            'motherTongues' => Masters::forSelect($masters->motherTongues()),
            'stars' => Masters::forSelect($masters->stars()),
            'rasis' => Masters::forSelect($masters->rasis()),
            'education' => Masters::forSelect($masters->education()),
            'occupations' => Masters::forSelect($masters->occupations()),
            'incomeBands' => Masters::forSelect($masters->incomeBands()),
            'countries' => Masters::forSelect($masters->countries()),
            'currentStates' => $this->statesFor($masters, $this->career->current_country_id),
            'currentDistricts' => $this->districtsFor($masters, $this->career->current_state_id),
            'permanentStates' => $this->statesFor($masters, $this->career->permanent_country_id),
            'permanentDistricts' => $this->districtsFor($masters, $this->career->permanent_state_id),
            'familyStatuses' => Masters::forSelect($masters->options('family_status')),
            'familyTypes' => Masters::forSelect($masters->options('family_type')),
            'familyValues' => Masters::forSelect($masters->options('family_values')),
        ])->layout('layouts::member', ['seo' => SeoData::private('Create your profile | Oppam Matrimony')]);
    }

    /** @return array<int, string> */
    private function statesFor(Masters $masters, string $countryId): array
    {
        return is_numeric($countryId) ? Masters::forSelect($masters->statesForCountry((int) $countryId)) : [];
    }

    /** @return array<int, string> */
    private function districtsFor(Masters $masters, string $stateId): array
    {
        return is_numeric($stateId) ? Masters::forSelect($masters->districtsForState((int) $stateId)) : [];
    }

    private function member(): User
    {
        $user = auth('web')->user();

        // Broker and staff logins own no matrimony profile (PRD §8.1).
        if (! $user instanceof User || $user->role !== UserRole::Member) {
            abort(404);
        }

        return $user;
    }
}
