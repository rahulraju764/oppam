<?php

declare(strict_types=1);

namespace App\Livewire\Member\Settings;

use App\Actions\Auth\ChangePassword;
use App\Actions\Auth\ChangePhone;
use App\Actions\Auth\LogoutOtherDevices;
use App\Actions\Auth\RemoveEmail;
use App\Actions\Auth\RequestEmailChange;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Auth\AccountChangeRefused;
use App\Exceptions\Auth\OtpInvalid;
use App\Exceptions\Auth\OtpThrottled;
use App\Models\User;
use App\Queries\Auth\RecentDevices;
use App\Rules\ValidMobileNumber;
use App\Support\Auth\MemberPassword;
use App\ValueObjects\PhoneNumber;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * /settings → Account (PRD M14 / M01; owner decisions 2026-11-08): mobile number (two codes —
 * ChangePhone), email (a link to prove it — RequestEmailChange / RemoveEmail), password
 * (ChangePassword) and recent sign-ins with "Sign out all other devices" (LogoutOtherDevices).
 * Every rule is in those Actions; this only shows the steps. Which step of the mobile change the
 * member is on comes from the server session (ChangePhone::progress), never from this component.
 */
#[Lazy]
final class AccountSettings extends Component
{
    public string $oldCode = '';

    public string $newCountry = PhoneNumber::DEFAULT_COUNTRY;

    public string $newMobile = '';

    public string $newCode = '';

    public bool $phoneOpen = false;

    public string $email = '';

    public string $currentPassword = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function placeholder(): View
    {
        return view('livewire.member.settings.placeholder');
    }

    // ---- Mobile --------------------------------------------------------------------------------

    public function startPhoneChange(ChangePhone $change): void
    {
        $this->resetErrorBag();
        $this->reset('oldCode', 'newMobile', 'newCode');

        $this->attempt(fn () => $change->start($this->member(), (string) request()->ip()), 'oldCode', function (): void {
            $this->phoneOpen = true;
            $this->dispatch('toast', type: 'success', title: __('We sent a code to your current number.'));
        });
    }

    public function confirmOldCode(ChangePhone $change): void
    {
        $this->validate(['oldCode' => ['required', 'string', 'regex:/^\d{6}$/']], $this->codeMessages());

        $this->attempt(fn () => $change->confirmOld($this->member(), $this->oldCode), 'oldCode', fn () => $this->reset('oldCode'));
    }

    public function sendNewCode(ChangePhone $change): void
    {
        $this->validate([
            'newCountry' => ['required', Rule::in(PhoneNumber::countryCodes())],
            'newMobile' => ['required', 'string', 'max:20', new ValidMobileNumber($this->newCountry)],
        ]);

        $this->attempt(
            fn () => $change->sendNew($this->member(), PhoneNumber::fromParts($this->newCountry, $this->newMobile), (string) request()->ip()),
            'newMobile',
            fn () => $this->dispatch('toast', type: 'success', title: __('If this number can be used, a code is on its way to it.')),
        );
    }

    public function confirmNewCode(ChangePhone $change): void
    {
        $this->validate(['newCode' => ['required', 'string', 'regex:/^\d{6}$/']], $this->codeMessages('newCode'));

        $this->attempt(fn () => $change->confirmNew($this->member(), $this->newCode, request()), 'newCode', function (): void {
            $this->reset('oldCode', 'newMobile', 'newCode', 'phoneOpen');
            session()->flash('status', __('Your mobile number was changed. Every other device was signed out.'));
            $this->dispatch('toast', type: 'success', title: __('Your mobile number was changed.'));
        });
    }

    public function cancelPhoneChange(ChangePhone $change): void
    {
        $change->cancel();
        $this->reset('oldCode', 'newMobile', 'newCode', 'phoneOpen');
        $this->resetErrorBag();
    }

    // ---- Email ---------------------------------------------------------------------------------

    public function sendEmailLink(RequestEmailChange $request): void
    {
        $this->validate(['email' => ['required', 'string', 'email:rfc', 'max:255']]);

        $this->attempt(fn () => $request->handle($this->member(), $this->email), 'email', function (): void {
            $this->dispatch('toast', type: 'success', title: __('If this address can be used, a confirmation link is on its way to it.'));
        });
    }

    public function removeEmail(RemoveEmail $remove): void
    {
        $this->attempt(fn () => $remove->handle($this->member()), 'email', function (): void {
            $this->reset('email');
            $this->dispatch('close-modal', name: 'remove-email');
            $this->dispatch('toast', type: 'success', title: __('Your email address was removed.'));
        });
    }

    // ---- Password ------------------------------------------------------------------------------

    public function changePassword(ChangePassword $change): void
    {
        $this->validate([
            'currentPassword' => ['required', 'string', 'max:72'],
            'password' => ['required', 'string', 'max:72', 'confirmed', MemberPassword::rule()],
        ]);

        $this->attempt(fn () => $change->handle($this->member(), $this->currentPassword, $this->password, request()), 'currentPassword', function (): void {
            $this->reset('currentPassword', 'password', 'password_confirmation');
            session()->flash('status', __('Your password was changed. Every other device was signed out.'));
            $this->dispatch('toast', type: 'success', title: __('Your password was changed.'));
        });
        $this->reset('password', 'password_confirmation');
    }

    // ---- Devices -------------------------------------------------------------------------------

    public function signOutOtherDevices(LogoutOtherDevices $logout): void
    {
        $this->attempt(fn () => $logout->handle($this->member(), request()), 'devices', function (): void {
            session()->flash('status', __('Every other device was signed out.'));
            $this->dispatch('toast', type: 'success', title: __('Every other device was signed out.'));
        });
    }

    public function render(ChangePhone $change, RecentDevices $devices): View
    {
        $member = $this->member();
        $cookie = request()->cookie((string) config('oppam.auth.device_cookie'));

        return view('livewire.member.settings.account-settings', [
            'phoneMasked' => PhoneNumber::fromE164($member->phone)->masked(),
            'progress' => $change->progress($member),
            'currentEmail' => $member->email,
            'emailVerified' => $member->email_verified_at !== null,
            'devices' => $devices->for($member, is_string($cookie) ? $cookie : null),
            'countryOptions' => PhoneNumber::countryOptions(),
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }

    /**
     * Run an Action; a refusal becomes an inline error on $field (and nothing else changes).
     *
     * @param  callable(): mixed  $action
     * @param  callable(): mixed  $done
     */
    private function attempt(callable $action, string $field, callable $done): void
    {
        try {
            $action();
        } catch (OtpInvalid|OtpThrottled|AccountChangeRefused $refused) {
            $this->addError($field, $refused->getMessage());

            return;
        } catch (ImpersonationRestricted $restricted) {
            $this->dispatch('toast', type: 'danger', title: $restricted->getMessage());

            return;
        } catch (NotFoundHttpException) {
            $this->dispatch('toast', type: 'danger', title: __('Your account isn’t available to change right now.'));

            return;
        }

        $done();
    }

    /** @return array<string, string> */
    private function codeMessages(string $field = 'oldCode'): array
    {
        return [
            $field.'.required' => __('Enter the 6-digit code from the SMS.'),
            $field.'.regex' => __('The code has 6 digits.'),
        ];
    }

    private function member(): User
    {
        $user = auth('web')->user();
        abort_unless($user instanceof User, 404);

        return $user;
    }
}
