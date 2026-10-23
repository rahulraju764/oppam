<?php

declare(strict_types=1);

namespace App\Livewire\Broker;

use App\Actions\Broker\ApplyAsBroker;
use App\Data\Content\SeoData;
use App\Enums\Flag;
use App\Exceptions\Auth\RegistrationFailed;
use App\Livewire\Forms\BrokerApplicationForm;
use App\Livewire\Member\Auth\VerifyOtp;
use App\Services\Masters\Masters;
use App\Support\Facades\Feature;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * /broker/apply (PRD §11A B.11, B.3 "self-apply … behind feature flag broker.self_apply"): the
 * public application — name, bureau name, mobile, district, PAN, password. ApplyAsBroker creates
 * the APPLIED bureau; the mobile is proved on the normal /verify-otp screen, which signs the
 * applicant in and opens the portal (KYC next). With the flag off the page does not exist (404).
 */
final class Apply extends Component
{
    public BrokerApplicationForm $form;

    public function mount(): void
    {
        abort_unless(Feature::active(Flag::BrokerSelfApply), 404);
    }

    public function apply(ApplyAsBroker $apply): mixed
    {
        $data = $this->form->toData();

        try {
            $result = $apply->handle($data, (string) request()->ip());
        } catch (RegistrationFailed $failure) {
            $this->form->reset('password');
            $this->addError('form.'.$failure->field, $failure->getMessage());

            return null;
        } finally {
            $this->form->reset('password');   // never sent back to the browser in the snapshot
        }

        VerifyOtp::startPending($result->phone, $result->user?->id);
        if ($result->otpNotice !== null) {
            session()->flash('otp_notice', $result->otpNotice);
        }

        return $this->redirectRoute('register.verify', navigate: true);
    }

    public function render(Masters $masters): View
    {
        return view('livewire.broker.apply', [
            'districts' => Masters::forSelect($masters->allDistricts()),
        ])->layout('layouts::broker', ['plain' => true, 'seo' => SeoData::private('Join as a marriage bureau | Oppam Matrimony')]);
    }
}
