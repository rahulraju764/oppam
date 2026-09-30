<?php

declare(strict_types=1);

namespace App\Livewire\Member\Auth;

use App\Actions\Auth\CompleteRegistration;
use App\Actions\Auth\SendOtp;
use App\Data\Content\SeoData;
use App\Enums\OtpPurpose;
use App\Exceptions\Auth\LoginFailed;
use App\Exceptions\Auth\OtpInvalid;
use App\Exceptions\Auth\OtpThrottled;
use App\Models\User;
use App\Support\Navigation\MemberLanding;
use App\ValueObjects\PhoneNumber;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Component;

/**
 * /verify-otp — step 2 of registration (M01): enter the 6-digit code, or resend it after the
 * 30-second countdown. The pending registration (number + new account id, or no id when the
 * number already had an account) lives in the SERVER session only, set by the registration
 * forms. The screen looks and behaves the same in both cases (R-M01-4).
 */
final class VerifyOtp extends Component
{
    public const PENDING_SESSION_KEY = 'registration.pending';

    public string $code = '';

    /** Seconds until "Resend" is enabled (drives the Alpine countdown). */
    public int $resendIn = 30;

    /** Called by the registration forms. $userId is null when the number already had an account. */
    public static function startPending(PhoneNumber $phone, ?string $userId): void
    {
        session()->put(self::PENDING_SESSION_KEY, ['phone' => $phone->e164(), 'user_id' => $userId]);
    }

    public function mount(): mixed
    {
        if ($this->pendingPhone() === null) {
            return $this->redirectRoute('register', navigate: true);
        }

        $this->resendIn = (int) config('oppam.auth.otp_resend_seconds');

        return null;
    }

    public function verify(CompleteRegistration $complete, MemberLanding $landing): mixed
    {
        $this->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']], [
            'code.required' => __('Enter the 6-digit code from the SMS.'),
            'code.regex' => __('The code has 6 digits.'),
        ]);

        $phone = $this->pendingPhone();

        if ($phone === null) {
            return $this->redirectRoute('register', navigate: true);
        }

        try {
            $user = $complete->handle($phone, $this->pendingUser()?->id, $this->code, request());
        } catch (OtpInvalid|LoginFailed $failure) {
            $this->reset('code');
            $this->addError('code', $failure->getMessage());

            return null;
        }

        session()->forget(self::PENDING_SESSION_KEY);

        return $this->redirect($landing->url($user), navigate: false);
    }

    public function resend(SendOtp $sendOtp): void
    {
        $phone = $this->pendingPhone();

        if ($phone === null) {
            $this->redirectRoute('register', navigate: true);

            return;
        }

        try {
            // No pending account (number already registered): the limits are spent, nothing is sent.
            $sendOtp->handle($phone, OtpPurpose::Register, (string) request()->ip(), $this->pendingUser());
        } catch (OtpThrottled $throttled) {
            $this->resendIn = $throttled->retryAfterSeconds;
            $this->addError('resend', $throttled->getMessage());

            return;
        }

        $this->resendIn = (int) config('oppam.auth.otp_resend_seconds');
        $this->dispatch('otp-resent');
        session()->flash('otp_status', __('We have sent a new code.'));
    }

    public function render(): View
    {
        return view('livewire.member.auth.verify-otp', [
            'maskedPhone' => $this->pendingPhone()?->masked() ?? '',
        ])->layout('layouts::public', ['seo' => SeoData::private('Verify your mobile | Oppam Matrimony')]);
    }

    private function pendingPhone(): ?PhoneNumber
    {
        $pending = session(self::PENDING_SESSION_KEY);

        if (! is_array($pending) || ! is_string($pending['phone'] ?? null)) {
            return null;
        }

        try {
            return PhoneNumber::fromE164($pending['phone']);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** The new, still-unverified account waiting for this code — null if none. */
    private function pendingUser(): ?User
    {
        $pending = session(self::PENDING_SESSION_KEY);
        $id = is_array($pending) ? ($pending['user_id'] ?? null) : null;

        if (! is_string($id)) {
            return null;
        }

        return User::query()->whereKey($id)->whereNull('phone_verified_at')->first();
    }
}
