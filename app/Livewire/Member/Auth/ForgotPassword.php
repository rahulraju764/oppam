<?php

declare(strict_types=1);

namespace App\Livewire\Member\Auth;

use App\Actions\Auth\RequestMemberOtp;
use App\Actions\Auth\ResetPassword;
use App\Data\Content\SeoData;
use App\Enums\OtpPurpose;
use App\Exceptions\Auth\LoginFailed;
use App\Exceptions\Auth\OtpInvalid;
use App\Exceptions\Auth\OtpThrottled;
use App\Rules\ValidMobileNumber;
use App\Support\Auth\MemberPassword;
use App\Support\Navigation\MemberLanding;
use App\ValueObjects\PhoneNumber;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * /forgot-password (template forgot-password.php, PRD §8.2 method 3): mobile → code → new
 * password. Step 2 is shown whether or not the number is registered (R-M01-4). The email-link
 * option arrives with email verification (M14); an unverified address can't receive resets.
 */
final class ForgotPassword extends Component
{
    public string $countryCode = PhoneNumber::DEFAULT_COUNTRY;

    public string $mobile = '';

    public bool $codeSent = false;

    public string $code = '';

    public string $password = '';

    public string $password_confirmation = '';

    public int $resendIn = 0;

    public function sendCode(RequestMemberOtp $request): void
    {
        $this->validatePhone();

        try {
            $request->handle(PhoneNumber::fromParts($this->countryCode, $this->mobile), OtpPurpose::PasswordReset, (string) request()->ip());
        } catch (OtpThrottled $throttled) {
            $this->resendIn = $throttled->retryAfterSeconds;
            $this->addError('mobile', $throttled->getMessage());

            return;
        }

        $this->codeSent = true;
        $this->resendIn = (int) config('oppam.auth.otp_resend_seconds');
        $this->dispatch('otp-resent');
    }

    public function changeNumber(): void
    {
        $this->reset('codeSent', 'code', 'password', 'password_confirmation', 'resendIn');
        $this->resetErrorBag();
    }

    public function resetPassword(ResetPassword $reset, MemberLanding $landing): mixed
    {
        $this->validatePhone();
        $this->validate([
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
            'password' => ['required', 'string', 'max:72', 'confirmed', MemberPassword::rule()],
        ], [
            'code.required' => __('Enter the 6-digit code from the SMS.'),
            'code.regex' => __('The code has 6 digits.'),
        ]);

        try {
            $user = $reset->handle(PhoneNumber::fromParts($this->countryCode, $this->mobile), $this->code, $this->password, request());
        } catch (OtpInvalid|LoginFailed $failure) {
            $this->reset('code');
            $this->addError('code', $failure->getMessage());

            return null;
        }

        session()->flash('status', __('Your password has been changed. Other devices have been logged out.'));

        return $this->redirect($landing->url($user), navigate: false);
    }

    public function render(): View
    {
        return view('livewire.member.auth.forgot-password', [
            'countryOptions' => PhoneNumber::countryOptions(),
            'maskedPhone' => $this->codeSent ? PhoneNumber::tryFromParts($this->countryCode, $this->mobile)?->masked() : null,
        ])->layout('layouts::public', ['seo' => SeoData::private('Forgot password | Oppam Matrimony')]);
    }

    private function validatePhone(): void
    {
        $this->validate([
            'countryCode' => ['required', Rule::in(PhoneNumber::countryCodes())],
            'mobile' => ['required', 'string', 'max:20', new ValidMobileNumber($this->countryCode)],
        ]);
    }
}
