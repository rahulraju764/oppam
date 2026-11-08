<?php

declare(strict_types=1);

namespace App\Livewire\Member\Auth;

use App\Actions\Auth\ResetPasswordByEmail;
use App\Data\Content\SeoData;
use App\Exceptions\Auth\AccountChangeRefused;
use App\Exceptions\Auth\LoginFailed;
use App\Support\Auth\MemberPassword;
use App\Support\Navigation\MemberLanding;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * /reset-password/{token} (PRD §8.2 method 3, email link; P8.2b): choose a new password. The token
 * is checked only when the form is sent (ResetPasswordByEmail), so opening the page — or a mail
 * scanner fetching it — never uses the link up.
 */
final class ResetPasswordEmail extends Component
{
    #[Locked]
    public string $token = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    public function resetPassword(ResetPasswordByEmail $reset, MemberLanding $landing): mixed
    {
        $this->validate(['password' => ['required', 'string', 'max:72', 'confirmed', MemberPassword::rule()]]);

        try {
            $user = $reset->handle($this->token, $this->password, request());
        } catch (AccountChangeRefused|LoginFailed $failure) {
            $this->reset('password', 'password_confirmation');
            $this->addError('password', $failure->getMessage());

            return null;
        }

        session()->flash('status', __('Your password has been changed. Other devices have been logged out.'));

        return $this->redirect($landing->url($user), navigate: false);
    }

    public function render(): View
    {
        return view('livewire.member.auth.reset-password-email')
            ->layout('layouts::public', ['seo' => SeoData::private('Choose a new password | Oppam Matrimony')]);
    }
}
