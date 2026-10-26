<?php

declare(strict_types=1);

namespace App\Livewire\Broker\Staff;

use App\Actions\Broker\Staff\AcceptStaffInvite;
use App\Data\Content\SeoData;
use App\Exceptions\Auth\OtpInvalid;
use App\Exceptions\Auth\OtpThrottled;
use App\Exceptions\Broker\StaffRefused;
use App\ValueObjects\PhoneNumber;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * /broker/staff/invite/{token} (§11A B.8 / B.11 "signed invite acceptance"): the invited person sets
 * a password, then enters the code texted to the INVITED mobile, and lands in the portal. Guests
 * only. The token is locked; every step is AcceptStaffInvite, which finds the invitation by the
 * token's hash. An unknown, used, expired or cancelled link — whatever the reason — shows one
 * generic "no longer valid" page. The pending password hash stays in the server session only.
 */
final class AcceptInvite extends Component
{
    private const SESSION_PREFIX = 'staff_invite.';

    #[Locked]
    public string $token = '';

    #[Locked]
    public string $step = 'password';

    public string $password = '';

    public string $password_confirmation = '';

    public string $code = '';

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    public function start(AcceptStaffInvite $accept): void
    {
        try {
            $hash = $accept->start($this->token, $this->password, $this->password_confirmation, (string) request()->ip());
        } catch (StaffRefused $refused) {
            $this->addError('password', $refused->getMessage());

            return;
        } catch (OtpThrottled $throttled) {
            $this->addError('password', $throttled->getMessage());

            return;
        } finally {
            $this->reset('password', 'password_confirmation');   // never kept in the component state
        }

        session()->put($this->sessionKey(), $hash);
        $this->step = 'code';
    }

    public function verify(AcceptStaffInvite $accept): mixed
    {
        $this->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']], [
            'code.required' => __('Enter the 6-digit code from the SMS.'),
            'code.regex' => __('The code has 6 digits.'),
        ]);

        $hash = session($this->sessionKey());
        if (! is_string($hash)) {
            $this->step = 'password';

            return null;
        }

        try {
            $accept->verify($this->token, $this->code, $hash, request());
        } catch (OtpInvalid|StaffRefused $failure) {
            $this->reset('code');
            $this->addError('code', $failure->getMessage());

            return null;
        }

        session()->forget($this->sessionKey());

        return $this->redirectRoute('broker.dashboard', navigate: false);
    }

    public function back(): void
    {
        session()->forget($this->sessionKey());
        $this->reset('code');
        $this->step = 'password';
    }

    public function render(AcceptStaffInvite $accept): View
    {
        $invitation = $accept->find($this->token);

        return view('livewire.broker.staff.accept-invite', [
            'invitation' => $invitation,
            'bureau' => $invitation?->broker?->displayName(),
            'maskedPhone' => $invitation !== null ? PhoneNumber::fromE164($invitation->phone)->masked() : '',
        ])->layout('layouts::public', ['seo' => SeoData::private('Join your bureau | Oppam Matrimony')]);
    }

    private function sessionKey(): string
    {
        return self::SESSION_PREFIX.hash('sha256', $this->token);
    }
}
