<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Auth\RegisterMember;
use App\Exceptions\Auth\RegistrationFailed;
use App\Livewire\Forms\RegistrationForm;
use App\Livewire\Member\Auth\VerifyOtp;

/**
 * Shared submit for the two registration forms (home hero and /register, M01): validate,
 * RegisterMember, remember the pending registration in the SERVER session (never the browser),
 * then go to /verify-otp. The flow is identical whether or not the number already had an
 * account (RegisterMember then created nothing and texted the owner instead).
 *
 * @property RegistrationForm $form
 */
trait SubmitsRegistration
{
    public function updatedFormCreatedFor(): void
    {
        $this->form->applyDerivedGender();
    }

    public function register(RegisterMember $register): mixed
    {
        $data = $this->form->toData();

        try {
            $result = $register->handle($data, (string) request()->ip());
        } catch (RegistrationFailed $failure) {
            $this->form->reset('password');   // don't send it back to the browser in the snapshot
            $this->addError('form.'.$failure->field, $failure->getMessage());

            return null;
        }

        VerifyOtp::startPending($result->phone, $result->user?->id);

        if ($result->otpNotice !== null) {
            session()->flash('otp_notice', $result->otpNotice);
        }

        $this->form->reset('password');

        return $this->redirectRoute('register.verify', navigate: true);
    }
}
