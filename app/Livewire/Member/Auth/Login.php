<?php

declare(strict_types=1);

namespace App\Livewire\Member\Auth;

use App\Actions\Auth\LoginWithOtp;
use App\Actions\Auth\LoginWithPassword;
use App\Actions\Auth\RequestMemberOtp;
use App\Data\Content\SeoData;
use App\Enums\OtpPurpose;
use App\Exceptions\Auth\LoginFailed;
use App\Exceptions\Auth\OtpInvalid;
use App\Exceptions\Auth\OtpThrottled;
use App\Rules\ValidMobileNumber;
use App\Support\Navigation\MemberLanding;
use App\ValueObjects\PhoneNumber;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * /login (template login.php, PRD §8.2): password with mobile / email / profile ID, or mobile +
 * one-time code. Messages never reveal whether an account exists (R-M01-4).
 */
final class Login extends Component
{
    public const MODE_PASSWORD = 'password';

    public const MODE_OTP = 'otp';

    public string $mode = self::MODE_PASSWORD;

    public string $loginId = '';

    public string $password = '';

    public bool $remember = false;

    public string $countryCode = PhoneNumber::DEFAULT_COUNTRY;

    public string $mobile = '';

    public string $code = '';

    public bool $codeSent = false;

    public int $resendIn = 0;

    public function useOtp(): void
    {
        $this->mode = self::MODE_OTP;
        $this->resetErrorBag();
    }

    public function usePassword(): void
    {
        $this->mode = self::MODE_PASSWORD;
        $this->reset('code', 'codeSent', 'resendIn');
        $this->resetErrorBag();
    }

    public function loginWithPassword(LoginWithPassword $login, MemberLanding $landing): mixed
    {
        $this->validate([
            'loginId' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ], [], ['loginId' => __('mobile number, email or profile ID')]);

        try {
            $user = $login->handle($this->loginId, $this->password, $this->remember, request());
        } catch (LoginFailed $failure) {
            $this->reset('password');
            $this->addError('loginId', $failure->getMessage());

            return null;
        }

        return $this->redirect(session()->pull('url.intended', $landing->url($user)), navigate: false);
    }

    public function sendCode(RequestMemberOtp $request): void
    {
        $this->validatePhone();

        try {
            $request->handle(PhoneNumber::fromParts($this->countryCode, $this->mobile), OtpPurpose::Login, (string) request()->ip());
        } catch (OtpThrottled $throttled) {
            $this->resendIn = $throttled->retryAfterSeconds;
            $this->addError('mobile', $throttled->getMessage());

            return;
        }

        $this->codeSent = true;
        $this->resendIn = (int) config('oppam.auth.otp_resend_seconds');
        $this->dispatch('otp-resent');
    }

    public function loginWithOtp(LoginWithOtp $login, MemberLanding $landing): mixed
    {
        $this->validatePhone();
        $this->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']], [
            'code.required' => __('Enter the 6-digit code from the SMS.'),
            'code.regex' => __('The code has 6 digits.'),
        ]);

        try {
            $user = $login->handle(PhoneNumber::fromParts($this->countryCode, $this->mobile), $this->code, $this->remember, request());
        } catch (OtpInvalid|LoginFailed $failure) {
            $this->reset('code');
            $this->addError('code', $failure->getMessage());

            return null;
        }

        return $this->redirect(session()->pull('url.intended', $landing->url($user)), navigate: false);
    }

    public function render(): View
    {
        return view('livewire.member.auth.login', [
            'countryOptions' => PhoneNumber::countryOptions(),
        ])->layout('layouts::public', ['seo' => new SeoData(
            title: 'Member Login | Oppam Matrimony Kerala',
            description: 'Securely access your Oppam Matrimony account to manage matches, profile updates, and interests.',
            keywords: 'Kerala Matrimony, Oppam Matrimony, Malayali Matrimony, Kerala Brides, Kerala Grooms,Oppam Matrimony Login',
            ogTitle: 'Login to Oppam Matrimony',
            ogDescription: 'Access your account and continue your search for the perfect life partner.',
        )]);
    }

    private function validatePhone(): void
    {
        $this->validate([
            'countryCode' => ['required', Rule::in(PhoneNumber::countryCodes())],
            'mobile' => ['required', 'string', 'max:20', new ValidMobileNumber($this->countryCode)],
        ]);
    }
}
