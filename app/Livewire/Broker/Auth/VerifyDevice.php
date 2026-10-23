<?php

declare(strict_types=1);

namespace App\Livewire\Broker\Auth;

use App\Actions\Broker\VerifyBrokerDevice;
use App\Data\Content\SeoData;
use App\Domain\Broker\TrustedDevices;
use App\Exceptions\Auth\OtpInvalid;
use App\Exceptions\Auth\OtpThrottled;
use App\Models\User;
use App\ValueObjects\PhoneNumber;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * /broker/verify-device (PRD §8.1 "Owner and Manager must pass an OTP challenge on each new
 * device"): send a code to the login's own mobile, enter it, and this device is trusted; then the
 * page the broker wanted opens. The code is only sent on request (never on page load, so a refresh
 * can't spend the SMS limits).
 */
final class VerifyDevice extends Component
{
    public string $code = '';

    public bool $sent = false;

    public int $resendIn = 0;

    public function mount(TrustedDevices $devices): mixed
    {
        if ($devices->isTrusted($this->user(), request())) {
            return $this->redirectRoute('broker.dashboard', navigate: false);
        }

        return null;
    }

    public function send(VerifyBrokerDevice $device): void
    {
        try {
            $device->send($this->user(), request());
        } catch (OtpThrottled $throttled) {
            $this->resendIn = $throttled->retryAfterSeconds;
            $this->addError('code', $throttled->getMessage());

            return;
        }

        $this->sent = true;
        $this->resendIn = (int) config('oppam.auth.otp_resend_seconds');
    }

    public function verify(VerifyBrokerDevice $device): mixed
    {
        $this->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']], [
            'code.required' => __('Enter the 6-digit code from the SMS.'),
            'code.regex' => __('The code has 6 digits.'),
        ]);

        try {
            $device->verify($this->user(), $this->code, request());
        } catch (OtpInvalid $invalid) {
            $this->reset('code');
            $this->addError('code', $invalid->getMessage());

            return null;
        }

        return $this->redirect(session()->pull('url.intended', route('broker.dashboard')), navigate: false);
    }

    public function render(): View
    {
        return view('livewire.broker.auth.verify-device', [
            'maskedPhone' => PhoneNumber::fromE164($this->user()->phone)->masked(),
        ])->layout('layouts::broker', ['plain' => true, 'seo' => SeoData::private('Confirm this device | Oppam Matrimony')]);
    }

    private function user(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
