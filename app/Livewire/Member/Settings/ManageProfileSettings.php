<?php

declare(strict_types=1);

namespace App\Livewire\Member\Settings;

use App\Actions\Content\ConfirmPartnerSuccessStory;
use App\Actions\Content\SubmitMemberSuccessStory;
use App\Actions\Profile\CloseAccount;
use App\Actions\Profile\HideProfile;
use App\Actions\Profile\RequestDataExport;
use App\Actions\Profile\UnhideProfile;
use App\Actions\Profile\WithdrawMemberSuccessStory;
use App\Data\Content\MemberStoryData;
use App\Domain\Safety\BlockList;
use App\Enums\ClosureReason;
use App\Enums\ProfileStatus;
use App\Enums\SettingKey;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Profile\SettingsRefused;
use App\Models\DataExport;
use App\Models\Profile;
use App\Models\SuccessStory;
use App\Models\User;
use App\Support\Facades\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * /settings → Hide / delete (PRD M14, M16; P8.2c). Hide / show my profile, download my data, share
 * a success story ("Found my match on Oppam" → the profile is hidden, not deleted), answer a
 * partner's story, and close the account (recovery by signing in, `members.purge_after_days`). Every rule lives in its
 * Action; this only collects the input. Stories are addressed by their code and looked up by the
 * Action among the member's own — nothing here trusts an id from the browser.
 */
#[Lazy]
final class ManageProfileSettings extends Component
{
    use WithFileUploads;

    public string $closeReason = '';

    public string $closeFeedback = '';

    public string $closePassword = '';

    public bool $showStoryForm = false;

    public string $partnerCode = '';

    public string $weddingDate = '';

    public string $place = '';

    public string $quote = '';

    public string $storyBody = '';

    /** @var UploadedFile|null */
    public $storyPhoto = null;

    public function placeholder(): View
    {
        return view('livewire.member.settings.placeholder');
    }

    public function hide(HideProfile $hide): void
    {
        $this->run(function () use ($hide): void {
            $hide->handle($this->member());
            $this->dispatch('presence-hidden');
            $this->dispatch('toast', type: 'success', title: __('Your profile is hidden. Nobody can find or open it until you show it again — your existing chats still work.'));
        });
    }

    public function unhide(UnhideProfile $unhide): void
    {
        $this->run(function () use ($unhide): void {
            $unhide->handle($this->member());
            $this->dispatch('toast', type: 'success', title: __('Your profile is visible again.'));
        });
    }

    public function requestExport(RequestDataExport $request): void
    {
        $this->run(function () use ($request): void {
            $request->handle($this->member());
            $this->dispatch('toast', type: 'success', title: __('We’re preparing your data. We’ll let you know when it’s ready to download.'));
        });
    }

    public function openStoryForm(): void
    {
        $this->showStoryForm = true;
        $this->dispatch('close-modal', name: 'close-account');
    }

    public function cancelStory(): void
    {
        $this->reset('showStoryForm', 'partnerCode', 'weddingDate', 'place', 'quote', 'storyBody', 'storyPhoto');
        $this->resetErrorBag();
    }

    public function submitStory(SubmitMemberSuccessStory $submit): void
    {
        $this->validate([
            'partnerCode' => ['required', 'string', 'max:12', 'regex:/^\s*OPM\d{4,9}\s*$/i'],
            'weddingDate' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Kolkata')->addYear()->toDateString(), 'after:2000-01-01'],
            'place' => ['nullable', 'string', 'max:80'],
            'quote' => ['required', 'string', 'min:10', 'max:300'],
            'storyBody' => ['required', 'string', 'min:50', 'max:5000'],
            'storyPhoto' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ], [
            'partnerCode.regex' => __('Enter your partner’s profile ID, like OPM12345.'),
        ]);

        $this->run(function () use ($submit): void {
            $submit->handle($this->member(), new MemberStoryData(
                partnerCode: $this->partnerCode,
                weddingDate: $this->weddingDate,
                place: $this->place,
                quote: $this->quote,
                body: $this->storyBody,
                photo: $this->storyPhoto instanceof UploadedFile ? $this->storyPhoto : null,
            ));
            $this->cancelStory();
            $this->dispatch('presence-hidden');
            $this->dispatch('toast', type: 'success', title: __('Congratulations! Your story is with us. We’ve asked your partner to confirm, and your profile is now hidden.'));
        });
    }

    public function answerStory(string $code, bool $confirm, ConfirmPartnerSuccessStory $answer): void
    {
        $this->run(function () use ($code, $confirm, $answer): void {
            $answer->handle($this->member(), $code, $confirm);
            if ($confirm) {
                $this->dispatch('presence-hidden');
            }
            $this->dispatch('toast', type: 'success', title: $confirm
                ? __('Thank you — the story is confirmed. Your profile is now hidden.')
                : __('The story won’t be shared.'));
        });
    }

    public function withdrawStory(string $code, WithdrawMemberSuccessStory $withdraw): void
    {
        $this->run(function () use ($code, $withdraw): void {
            $withdraw->handle($this->member(), $code);
            $this->dispatch('toast', type: 'success', title: __('Your story has been withdrawn.'));
        });
    }

    public function closeAccount(CloseAccount $close): mixed
    {
        $this->validate([
            'closeReason' => ['required', Rule::enum(ClosureReason::class)],
            'closeFeedback' => ['nullable', 'string', 'max:1000'],
            'closePassword' => ['required', 'string', 'max:255'],
        ], [], ['closeReason' => __('reason'), 'closePassword' => __('password')]);

        $reason = ClosureReason::from($this->closeReason);
        if (! $reason->closesAccount()) {
            $this->openStoryForm();

            return null;
        }

        $closed = false;
        $this->run(function () use ($close, $reason, &$closed): void {
            try {
                $close->handle($this->member(), $reason, $this->closeFeedback, $this->closePassword);
                $closed = true;
            } finally {
                $this->reset('closePassword');
            }
        }, errorMap: ['password' => 'closePassword', 'feedback' => 'closeFeedback']);

        if (! $closed) {
            return null;
        }

        Auth::guard('web')->logout();
        session()->invalidate();
        session()->regenerateToken();
        session()->flash('status', __('Your account is closed. Changed your mind? Sign in within :days days to restore it.', ['days' => Settings::int(SettingKey::MembersPurgeAfterDays)]));

        return $this->redirectRoute('home', navigate: false);
    }

    public function render(): View
    {
        $member = $this->member();
        $profile = Profile::query()->where('user_id', $member->id)->first();

        $stories = $profile === null ? collect() : SuccessStory::query()
            ->whereNull('withdrawn_at')
            ->where(fn ($q) => $q->where('profile_a_id', $profile->id)->orWhere('profile_b_id', $profile->id))
            ->latest('created_at')
            ->limit(5)
            ->get();

        // Rule 4: a pair blocked since the story was shared never sees each other here either.
        if ($profile !== null && $stories->isNotEmpty()) {
            $hidden = array_flip(app(BlockList::class)->hiddenFrom($profile));
            $stories = $stories->reject(function (SuccessStory $s) use ($profile, $hidden): bool {
                $other = $s->profile_a_id === $profile->id ? $s->profile_b_id : $s->profile_a_id;

                return $other !== null && isset($hidden[$other]);
            })->values();
        }

        return view('livewire.member.settings.manage-profile-settings', [
            'profile' => $profile,
            'isHidden' => $profile?->status === ProfileStatus::Hidden,
            'canHide' => $profile?->status === ProfileStatus::Active,
            'exports' => DataExport::query()->where('user_id', $member->id)->latest('created_at')->limit(3)->get(),
            'waitingForMe' => $profile === null ? collect() : $stories->filter(fn (SuccessStory $s): bool => $s->profile_b_id === $profile->id && $s->consent_b_at === null)->values(),
            'myStories' => $profile === null ? collect() : $stories->reject(fn (SuccessStory $s): bool => $s->profile_b_id === $profile->id && $s->consent_b_at === null)->values(),
            'reasons' => ClosureReason::options(),
            'storyReason' => ClosureReason::MarriedViaOppam->value,
            'restoreDays' => Settings::int(SettingKey::MembersPurgeAfterDays),
        ]);
    }

    /**
     * Runs an Action and shows its refusal: a settings refusal or a support session → toast;
     * field errors stay on the form (mapped to this component's property names).
     *
     * @param  array<string, string>  $errorMap
     */
    private function run(callable $action, array $errorMap = []): void
    {
        $this->resetErrorBag();

        try {
            $action();
        } catch (SettingsRefused|ImpersonationRestricted $refused) {
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());
        } catch (ValidationException $invalid) {
            foreach ($invalid->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($errorMap[$field] ?? $field, $message);
                }
            }
        }
    }

    private function member(): User
    {
        $user = Auth::guard('web')->user();
        if (! $user instanceof User || ! $user->isActive()) {
            throw new NotFoundHttpException;
        }

        return $user;
    }
}
