<?php

declare(strict_types=1);

namespace App\Livewire\Member\Onboarding;

use App\Actions\Broker\EnterReferralCode;
use App\Actions\Profile\SubmitProfile;
use App\Data\Broker\ReferralCodeHintData;
use App\Data\Content\SeoData;
use App\Domain\Broker\BrokerCodeLookup;
use App\Domain\Profile\PendingTextEdits;
use App\Domain\Profile\ProfileRules;
use App\Domain\Profile\WizardProgress;
use App\Enums\Gender;
use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Enums\WizardStep;
use App\Exceptions\Profile\ProfileNotSubmittable;
use App\Livewire\Concerns\EditsWizardSections;
use App\Models\Broker;
use App\Models\ModerationItem;
use App\Models\Profile;
use App\Models\User;
use App\Services\Admin\Impersonation;
use App\Support\Navigation\MemberLanding;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * /onboarding/{step} — the profile wizard (M02, template profile-creation.php … profile-photos.php).
 * One component, one Form Object per step, each step saved by its own Action (which authorizes
 * and re-validates). Saves on Continue/Back and autosaves after 20 s without typing (Alpine →
 * autosave(), partial rules). Dependent lists are reactive. Sections, their dependencies and saves are
 * shared with the bureau form (EditsWizardSections). Step 6 submits for review
 * (SubmitProfile, R-M02-2). The profile is always the signed-in member's own. Option lists are
 * built by WizardOptions.
 */
final class Wizard extends Component
{
    use EditsWizardSections;

    private const AUTOSAVES_PER_MINUTE = 30;

    #[Locked]
    public int $step = 1;

    /** Last successful autosave (IST "h:i A"), shown as "Saved at …". */
    public ?string $savedAt = null;

    /** Step 1: bureau referral code (§11A B.5) — only while EnterReferralCode::canEnter. */
    public string $referralCode = '';

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
        $this->loadSections($profile);
        $this->referralCode = (string) $profile->broker_code_raw;

        return null;
    }

    /** The photo manager (step 6) changed photos: re-render so progress and completeness follow. */
    #[On('photos-changed')]
    public function photosChanged(): void {}

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

    /** Step 6 "Submit for review": save the step, then DRAFT/REJECTED → PENDING_REVIEW (R-M02-2). */
    public function submit(SubmitProfile $submit): mixed
    {
        $this->save(partial: false);
        $user = $this->member();

        // A live profile has nothing to submit: its changes are saved (text edits wait for review).
        if ($user->profile?->status === ProfileStatus::Active) {
            session()->flash('status', __('Your changes are saved. Edited names and "about" texts appear once reviewed.'));

            return $this->redirectRoute('member.profile.me', navigate: true);
        }

        try {
            $submit->handle($user, $user->profile ?? abort(404));
        } catch (ProfileNotSubmittable $e) {
            if ($e->step === null) {
                return $this->redirectRoute('member.onboarding.submitted', navigate: true);
            }

            $this->addError('submit', $e->getMessage());

            return null;
        }

        return $this->redirectRoute('member.onboarding.submitted', navigate: true);
    }

    private function save(bool $partial): void
    {
        $user = $this->member();
        $profile = $user->profile ?? abort(404);

        $step = WizardStep::from($this->step);

        // The referral code is resolved on "Continue" only — never by autosave / Back: a matching
        // code attributes for good (R-M13-3), so a half-typed one must not. Not in a support session.
        if ($step === WizardStep::Basic && ! $partial && ! app(Impersonation::class)->isActive()) {
            $this->validateOnly('referralCode', ['referralCode' => ['nullable', 'string', 'max:30']]);

            $code = BrokerCodeLookup::normalise($this->referralCode);

            if ($code !== null && $code !== $profile->broker_code_raw && EnterReferralCode::canEnter($profile)) {
                app(EnterReferralCode::class)->handle($user, $profile, $code);
            }
        }

        $this->saveSection($user, $profile, $step, $partial);
    }

    // ---- View ---------------------------------------------------------------------------------

    public function render(WizardOptions $options): View
    {
        $profile = $this->member()->profile;
        $current = WizardStep::from($this->step);

        return view('livewire.member.onboarding.wizard', [
            'current' => $current,
            'steps' => WizardStep::cases(),
            'progress' => $profile !== null ? new WizardProgress($profile) : null,
            'profile' => $profile,
            'locked' => $profile !== null ? ProfileRules::lockedFields($profile) : [],
            'rejectionNote' => $this->rejectionNote($profile),
            'pendingFields' => $this->pendingFieldLabels($profile),
            'phone' => $this->member()->phone,
            'referral' => $this->referralView($current, $profile),
            ...$options->forStep($current, $profile->gender ?? Gender::Female, $this->basic, $this->career, $this->preference, $this->contact),
        ])->layout('layouts::member', ['seo' => SeoData::private('Create your profile | Oppam Matrimony')]);
    }

    /**
     * Friendly names of a live profile's text fields waiting for review (R-M02-4).
     *
     * @return list<string>
     */
    private function pendingFieldLabels(?Profile $profile): array
    {
        if ($profile?->status !== ProfileStatus::Active) {
            return [];
        }

        $names = ProfileRules::attributes();

        return array_map(
            fn (string $key): string => $names[substr($key, (int) strpos($key, '.') + 1)] ?? $key,
            array_keys(app(PendingTextEdits::class)->pending($profile)),
        );
    }

    /**
     * Step 1's referral block: editable with a live hint, or the bureau the member was referred by
     * (read-only, R-M13-3), or nothing.
     *
     * @return array{editable: bool, hint: ReferralCodeHintData|null, bureau: string|null}|null
     */
    private function referralView(WizardStep $current, ?Profile $profile): ?array
    {
        if ($current !== WizardStep::Basic || $profile === null) {
            return null;
        }

        if (EnterReferralCode::canEnter($profile) && ! app(Impersonation::class)->isActive()) {
            $hint = trim($this->referralCode) === '' ? null : app(BrokerCodeLookup::class)->hint($this->referralCode);

            return ['editable' => true, 'hint' => $hint, 'bureau' => null];
        }

        $bureau = $profile->broker_verified && $profile->broker_id !== null
            ? Broker::query()->whereKey($profile->broker_id)->first()?->displayName()
            : null;

        return $bureau === null ? null : ['editable' => false, 'hint' => null, 'bureau' => $bureau];
    }

    /** The moderator's note for a rejected profile (R-M02-5), or null. */
    private function rejectionNote(?Profile $profile): ?string
    {
        return $profile?->status === ProfileStatus::Rejected ? ModerationItem::latestDecisionNote($profile) : null;
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
