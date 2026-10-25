<?php

declare(strict_types=1);

namespace App\Livewire\Broker\ManagedProfiles;

use App\Actions\Broker\ManagedProfiles\CreateManagedProfile;
use App\Actions\Broker\ManagedProfiles\SubmitManagedProfile;
use App\Actions\Broker\ManagedProfiles\UpdateManagedProfile;
use App\Data\Broker\ManagedProfileData;
use App\Data\Content\SeoData;
use App\Domain\Broker\BureauGate;
use App\Domain\Broker\ManagedProfileScope;
use App\Domain\Profile\ProfileRules;
use App\Domain\Profile\WizardProgress;
use App\Enums\BureauPermission;
use App\Enums\Gender;
use App\Enums\ProfileStatus;
use App\Enums\WizardStep;
use App\Exceptions\Profile\ProfileNotSubmittable;
use App\Livewire\Concerns\EditsWizardSections;
use App\Livewire\Member\Onboarding\WizardOptions;
use App\Models\ModerationItem;
use App\Models\Profile;
use App\Models\User;
use App\ValueObjects\PhoneNumber;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * /broker/profiles/create and /broker/profiles/{code}/edit — "Add profile" in one sitting (§11A
 * B.6): the client mobile, the consent confirmation and the six M02 sections as an accordion,
 * with the wizard's own Form Objects, rules and step Actions (EditsWizardSections; B.17 "the same
 * rule set as M02"). Nothing is written before the first "Save draft" (mobile + consent + the
 * step-1 basics → CreateManagedProfile); after that each section saves on "Save & continue" and
 * autosaves when idle, like the wizard. The profile is found inside the caller's
 * ManagedProfileScope on every request; the code is locked. Designed for phones in the field.
 */
final class Form extends Component
{
    use EditsWizardSections;

    private const AUTOSAVES_PER_MINUTE = 30;

    /** The profile once its draft exists; null on /create. */
    #[Locked]
    public ?string $code = null;

    /** The open accordion section (WizardStep value); changed only through open(). */
    #[Locked]
    public int $section = 1;

    public string $clientPhone = '';

    public bool $consent = false;

    public ?string $savedAt = null;

    public function mount(BureauGate $gate, ManagedProfileScope $scope, ?string $code = null): void
    {
        $user = $this->user();

        if ($code === null) {
            $gate->authorize($user, BureauPermission::ProfilesCreate);

            return;
        }

        $profile = $scope->find($gate->authorize($user, BureauPermission::ProfilesEdit), $code);
        abort_unless($user->can('editWizard', $profile), 404);

        $this->code = $profile->code;
        $this->clientPhone = (string) $profile->client_phone;
        $this->consent = $profile->broker_consent_confirmed_at !== null;
        $this->loadSections($profile);

        $requested = WizardStep::tryFrom((int) request()->query('section', '1')) ?? WizardStep::Basic;
        $this->section = (new WizardProgress($profile))->canOpen($requested) ? $requested->value : WizardStep::Basic->value;
    }

    /** Open another section; the one being left keeps what is valid so far (like the wizard's Back). */
    public function open(int $section): void
    {
        $target = WizardStep::tryFrom($section);
        if ($target === null || $this->code === null) {
            return;   // before the draft exists only "Basic" is open
        }

        $profile = $this->profile();
        try {
            $this->saveSection($this->user(), $profile, WizardStep::from($this->section), partial: true);
        } catch (ValidationException) {
            // Invalid values just aren't kept, as with autosave.
        }

        if ((new WizardProgress($profile->refresh()))->canOpen($target)) {
            $this->resetErrorBag();
            $this->section = $target->value;
        }
    }

    /**
     * "Save draft" (first time: creates the profile) / "Save & continue": full validation of the
     * open section, save, then the next section.
     */
    public function save(CreateManagedProfile $create): mixed
    {
        if ($this->code === null) {
            $this->basic->validate();
            $profile = $create->handle($this->user(), new ManagedProfileData($this->clientPhone, $this->consent), $this->basic->toData());

            session()->flash('status', __('Draft saved for :code. Continue with the next sections.', ['code' => $profile->code]));

            return $this->redirectRoute('broker.profiles.edit', ['code' => $profile->code, 'section' => WizardStep::Career->value], navigate: true);
        }

        $step = WizardStep::from($this->section);
        $this->saveSection($this->user(), $this->profile(), $step, partial: false);
        $this->savedAt = $this->now();

        if ($step->next() !== null) {
            $this->section = $step->next()->value;
        }

        return null;
    }

    /** Idle autosave once the draft exists: keeps whatever is valid so far, silently. */
    public function autosave(): void
    {
        $key = 'broker-form-autosave:'.$this->user()->id;

        if ($this->code === null || RateLimiter::tooManyAttempts($key, self::AUTOSAVES_PER_MINUTE)) {
            return;
        }

        RateLimiter::hit($key, 60);

        try {
            $this->saveSection($this->user(), $this->profile(), WizardStep::from($this->section), partial: true);
        } catch (ValidationException) {
            return;
        }

        $this->savedAt = $this->now();
    }

    /** Change the client mobile (the future claim key) of an existing draft or profile. */
    public function saveClientPhone(UpdateManagedProfile $update): void
    {
        if ($this->code === null) {
            return;
        }

        $profile = $update->handle($this->user(), $this->code, $this->clientPhone);
        $this->clientPhone = (string) $profile->client_phone;
        $this->dispatch('toast', type: 'success', message: __('Client mobile saved.'));
    }

    /** Save the open section, then send the finished profile to A04 (R-M13-14). */
    public function submit(SubmitManagedProfile $submit): mixed
    {
        if ($this->code === null) {
            return null;
        }

        $this->saveSection($this->user(), $this->profile(), WizardStep::from($this->section), partial: false);

        try {
            $submit->handle($this->user(), $this->code);
        } catch (ProfileNotSubmittable $e) {
            $this->addError('submit', $e->getMessage());

            return null;
        }

        session()->flash('status', __('Profile :code was sent for review. You’ll be told as soon as our team decides.', ['code' => $this->code]));

        return $this->redirectRoute('broker.profiles.show', ['code' => $this->code], navigate: true);
    }

    /** The photo manager changed photos: re-render so progress follows. */
    #[On('photos-changed')]
    public function photosChanged(): void {}

    public function render(WizardOptions $options, BureauGate $gate): View
    {
        $profile = $this->code !== null ? $this->profile() : null;
        $current = WizardStep::from($this->section);
        $user = $this->user();

        return view('livewire.broker.managed-profiles.form', [
            'profile' => $profile,
            'current' => $current,
            'steps' => WizardStep::cases(),
            'progress' => $profile !== null ? new WizardProgress($profile) : null,
            'locked' => $profile !== null ? ProfileRules::lockedFields($profile) : [],
            'rejectionNote' => $profile?->status === ProfileStatus::Rejected ? ModerationItem::latestDecisionNote($profile) : null,
            'canSubmit' => $profile !== null && in_array($profile->status, [ProfileStatus::Draft, ProfileStatus::Rejected], true)
                && $gate->allows($user, BureauPermission::ProfilesSubmit),
            'maskedPhone' => $profile?->client_phone !== null ? PhoneNumber::tryParse((string) $profile->client_phone)?->masked() : null,
            // Step partials of the M02 wizard: no referral block, no member mobile, the bureau's photo manager.
            'referral' => null,
            'phone' => null,
            'managedCode' => $profile?->code,
            ...$options->forStep($current, Gender::tryFrom($this->basic->gender) ?? $profile->gender ?? Gender::Female,
                $this->basic, $this->career, $this->preference, $this->contact),
        ])->layout('layouts::broker', ['seo' => SeoData::private(($profile !== null ? __('Edit :code', ['code' => $profile->code]) : __('Add a client profile')).' | Oppam Matrimony')]);
    }

    private function profile(): Profile
    {
        $staff = app(BureauGate::class)->authorize($this->user(), BureauPermission::ProfilesEdit);

        return app(ManagedProfileScope::class)->find($staff, (string) $this->code);
    }

    private function now(): string
    {
        return now()->timezone((string) config('oppam.display_timezone'))->format('g:i A');
    }

    private function user(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
