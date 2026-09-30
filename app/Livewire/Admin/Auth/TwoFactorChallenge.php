<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Auth;

use App\Actions\Admin\Auth\CompleteAdminLogin;
use App\Actions\Admin\Auth\VerifyAdminSecondFactor;
use App\Exceptions\Admin\AdminAuthenticationFailed;
use App\Services\Admin\PendingAdminLogin;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Admin sign-in, step 2 (A01): the 6-digit authenticator code, or a recovery code. Reachable only
 * with a pending (password-accepted, < 10 min old) login.
 */
#[Layout('layouts::admin-auth', ['title' => 'Two-factor authentication'])]
final class TwoFactorChallenge extends Component
{
    public string $code = '';

    public bool $useRecoveryCode = false;

    public function mount(PendingAdminLogin $pending): mixed
    {
        $admin = $pending->admin();

        if ($admin === null) {
            return $this->redirectRoute('admin.login', navigate: false);
        }

        return $admin->hasConfirmedTwoFactor() ? null : $this->redirectRoute('admin.two-factor.setup', navigate: false);
    }

    public function toggleRecovery(): void
    {
        $this->useRecoveryCode = ! $this->useRecoveryCode;
        $this->reset('code');
        $this->resetErrorBag();
    }

    public function verify(PendingAdminLogin $pending, VerifyAdminSecondFactor $verify, CompleteAdminLogin $complete): mixed
    {
        $this->validate(['code' => $this->useRecoveryCode ? 'required|string|max:20' : 'required|string|max:10']);

        $admin = $pending->admin();

        if ($admin === null) {
            session()->flash('status', AdminAuthenticationFailed::loginExpired()->getMessage());

            return $this->redirectRoute('admin.login', navigate: false);
        }

        try {
            $verify->handle($admin, $this->code, $this->useRecoveryCode);
        } catch (AdminAuthenticationFailed $failure) {
            $this->reset('code');
            $this->addError('code', $failure->getMessage());

            return null;
        }

        $complete->handle($admin, request());

        return $this->redirectRoute('admin.dashboard', navigate: false);
    }

    public function render(): View
    {
        return view('livewire.admin.auth.two-factor-challenge');
    }
}
