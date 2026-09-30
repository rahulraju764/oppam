<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Auth;

use App\Actions\Admin\Auth\AttemptAdminLogin;
use App\Exceptions\Admin\AdminAuthenticationFailed;
use App\Services\Admin\PendingAdminLogin;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Admin sign-in, step 1 (A01): email + password. On success the admin goes to the 2FA challenge,
 * or to first-time 2FA enrolment — never straight into the panel.
 */
#[Layout('layouts::admin-auth', ['title' => 'Sign in'])]
final class Login extends Component
{
    #[Validate('required|string|email|max:255')]
    public string $email = '';

    #[Validate('required|string|max:255')]
    public string $password = '';

    public function login(AttemptAdminLogin $attempt, PendingAdminLogin $pending): mixed
    {
        $this->validate();

        try {
            $admin = $attempt->handle($this->email, $this->password, (string) request()->ip());
        } catch (AdminAuthenticationFailed $failure) {
            $this->reset('password');
            $this->addError('email', $failure->getMessage());

            return null;
        }

        $pending->start($admin);

        return $this->redirectRoute($admin->hasConfirmedTwoFactor() ? 'admin.two-factor.challenge' : 'admin.two-factor.setup', navigate: false);
    }

    public function render(): View
    {
        return view('livewire.admin.auth.login');
    }
}
