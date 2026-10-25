<?php

declare(strict_types=1);

namespace App\Livewire\Member\Auth;

use App\Actions\Broker\ClaimManagedProfile;
use App\Data\Content\SeoData;
use App\Exceptions\Broker\ClaimUnavailable;
use App\Models\User;
use App\Support\Navigation\MemberLanding;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * /register/claim — "We found a profile for this number: Anjali M. (OPM88213). Is this you?"
 * (§11A B.6 claim flow, R-M13-15). Reached only straight after the registration OTP (VerifyOtp
 * sets a one-time session flag). Nothing here takes an id or code from the browser: the profile
 * shown and claimed is always the one stored with the member's own verified mobile
 * (ClaimManagedProfile). "No" leaves both profiles as they are and the wizard continues.
 */
final class ClaimProfile extends Component
{
    public const OFFER_SESSION_KEY = 'registration.claim_offer';

    public function mount(ClaimManagedProfile $claim, MemberLanding $landing): mixed
    {
        if (session(self::OFFER_SESSION_KEY) !== true || $claim->candidateFor($this->member()) === null) {
            session()->forget(self::OFFER_SESSION_KEY);

            return $this->redirect($landing->url($this->member()), navigate: false);
        }

        return null;
    }

    public function confirm(ClaimManagedProfile $claim, MemberLanding $landing): mixed
    {
        if (session(self::OFFER_SESSION_KEY) !== true) {
            return $this->redirect($landing->url($this->member()), navigate: false);
        }

        try {
            $claim->claim($this->member());
        } catch (ClaimUnavailable $unavailable) {
            session()->forget(self::OFFER_SESSION_KEY);
            session()->flash('status', $unavailable->getMessage());

            return $this->redirect($landing->url($this->member()->refresh()), navigate: false);
        }

        session()->forget(self::OFFER_SESSION_KEY);
        session()->flash('status', __('Welcome! This profile is now yours — you can chat, edit and manage it yourself.'));

        return $this->redirect($landing->url($this->member()->refresh()), navigate: false);
    }

    public function decline(MemberLanding $landing): mixed
    {
        session()->forget(self::OFFER_SESSION_KEY);

        return $this->redirect($landing->url($this->member()), navigate: false);
    }

    public function render(ClaimManagedProfile $claim): View
    {
        $profile = $claim->candidateFor($this->member());

        return view('livewire.member.auth.claim-profile', [
            'name' => $profile !== null ? ClaimManagedProfile::maskedName($profile) : '',
            'code' => $profile->code ?? '',
        ])->layout('layouts::public', ['seo' => SeoData::private('Is this you? | Oppam Matrimony')]);
    }

    private function member(): User
    {
        /** @var User */
        return auth()->user();
    }
}
