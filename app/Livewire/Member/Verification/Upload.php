<?php

declare(strict_types=1);

namespace App\Livewire\Member\Verification;

use App\Actions\Verification\SubmitVerification;
use App\Data\Content\SeoData;
use App\Data\Verification\VerificationSubmissionData;
use App\Enums\UserRole;
use App\Enums\VerificationDocType;
use App\Enums\VerificationStatus;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Models\Profile;
use App\Models\User;
use App\Models\VerificationRequest;
use App\Services\Admin\Impersonation;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * /verify (PRD M09, template verification.php): the four steps (mobile, email, government ID,
 * Verified badge) and — when no request is waiting and the profile isn't verified — the upload:
 * document type, number (only the last 4 are kept), the name as printed, front / back (JPG, PNG,
 * PDF ≤ 5 MB) and consent. Everything is decided by SubmitVerification; the member never sees a
 * document again after sending it. An admin support session sees the page but can't submit.
 * `.verification.changed` re-renders it live when an officer decides (F05).
 */
final class Upload extends Component
{
    use WithFileUploads;

    public string $docType = '';

    public string $number = '';

    public string $nameOnDocument = '';

    public ?TemporaryUploadedFile $front = null;

    public ?TemporaryUploadedFile $back = null;

    public bool $consent = false;

    public ?string $status = null;

    /** @return array<string, string> */
    public function getListeners(): array
    {
        return [
            'echo-private:App.Models.User.'.$this->member()->id.',.verification.changed' => '$refresh',
            'realtime-reconnected' => '$refresh',
        ];
    }

    public function mount(): void
    {
        $this->nameOnDocument = $this->profile()->fullName();
    }

    public function submit(SubmitVerification $submit): void
    {
        $front = $this->front instanceof TemporaryUploadedFile ? $this->front : null;
        $back = $this->back instanceof TemporaryUploadedFile ? $this->back : null;

        try {
            $submit->handle($this->member(), new VerificationSubmissionData(
                $this->docType, $this->number, $this->nameOnDocument, $front, $back, $this->consent,
            ));
        } catch (ImpersonationRestricted $restricted) {
            $this->dispatch('toast', type: 'error', message: $restricted->getMessage());

            return;
        } finally {
            // The temporary upload is unencrypted: it never outlives the request that used it.
            $front?->delete();
            $back?->delete();
            $this->front = null;
            $this->back = null;
        }

        $this->reset('docType', 'number', 'consent');
        $this->resetErrorBag();
        $this->status = __('Thank you — your document has been sent. We’ll let you know as soon as it is checked.');
    }

    public function render(Impersonation $impersonation): View
    {
        $user = $this->member();
        $profile = $this->profile();
        $latest = VerificationRequest::query()->where('profile_id', $profile->id)->latest('submitted_at')->latest('id')->first();
        $state = match (true) {
            $profile->is_verified => 'verified',
            $latest !== null && $latest->status->isOpen() => 'pending',
            default => 'form',
        };

        $steps = [
            ['state' => 'done', 'title' => __('Mobile number confirmed'),
                'text' => __(':phone — confirmed.', ['phone' => '•••• ••• '.substr($user->phone, -3)])],
            $user->canReceiveAccountMail()
                ? ['state' => 'done', 'title' => __('Email confirmed'), 'text' => __('Your email address is confirmed.')]
                : ['state' => 'todo', 'title' => __('Email confirmed'), 'text' => __('Optional. You can add and confirm an email address in your settings.')],
            match ($state) {
                'verified' => ['state' => 'done', 'title' => __('Government ID'), 'text' => __('Checked by our verification team.')],
                'pending' => ['state' => 'active', 'title' => __('Government ID'), 'text' => __('Sent on :date — being checked. Usually within two working days.', [
                    'date' => $latest->submitted_at->timezone((string) config('oppam.display_timezone'))->format('j M Y'),
                ])],
                default => ['state' => 'active', 'title' => __('Government ID'), 'text' => __('Upload one photo ID so we can confirm your name and date of birth.')],
            },
            ['state' => $state === 'verified' ? 'done' : 'todo', 'title' => __('Verified badge on your profile'),
                'text' => $state === 'verified' ? __('Shown on your profile and in search results.') : __('Added automatically once the ID check passes.')],
        ];

        $type = VerificationDocType::tryFrom($this->docType);

        return view('livewire.member.verification.upload', [
            'state' => $state,
            'steps' => $steps,
            'done' => count(array_filter($steps, fn (array $step): bool => $step['state'] === 'done')),
            // Guidance from the last decision when the member is asked to try again.
            'guidance' => $state === 'form' && $latest !== null ? match ($latest->status) {
                VerificationStatus::Rejected => $latest->memberGuidance(),
                VerificationStatus::Revoked => __('We need to check your ID again. Please upload a current government ID.'),
                default => null,
            } : null,
            'docTypes' => VerificationDocType::options(),
            'numberHint' => $type?->numberHint() ?? __('As printed on the document. We keep only the last 4 characters.'),
            'supportSession' => $impersonation->isActive(),
            'maxMb' => intdiv((int) config('oppam.verification.max_kb'), 1024),
        ])->layout('layouts::member', ['seo' => SeoData::private('Verify Your Profile | Oppam Matrimony')]);
    }

    private function profile(): Profile
    {
        return $this->member()->profile ?? abort(404);
    }

    private function member(): User
    {
        $user = auth('web')->user();

        if (! $user instanceof User || $user->role !== UserRole::Member) {
            abort(404);
        }

        return $user;
    }
}
