<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Auth;

use App\Actions\Admin\Auth\CompleteAdminLogin;
use App\Actions\Admin\Auth\EnrolAdminTwoFactor;
use App\Exceptions\Admin\AdminAuthenticationFailed;
use App\Models\AdminUser;
use App\Services\Admin\PendingAdminLogin;
use App\Services\Admin\TwoFactorAuthenticator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * First-login 2FA enrolment (PRD A01, mandatory): scan the QR code, confirm one code, then see
 * the 10 recovery codes once before entering the panel. Reachable only with a pending login of an
 * admin who has not enrolled yet. The secret is never sent to the browser except as the QR image
 * and the manual-entry key the admin must type into their app.
 */
#[Layout('layouts::admin-auth', ['title' => 'Set up two-factor authentication'])]
final class TwoFactorSetup extends Component
{
    #[Locked]
    public string $qrCodeDataUri = '';

    #[Locked]
    public string $manualKey = '';

    public string $code = '';

    /** @var list<string> */
    #[Locked]
    public array $recoveryCodes = [];

    public function mount(PendingAdminLogin $pending, EnrolAdminTwoFactor $enrol, TwoFactorAuthenticator $twoFactor): mixed
    {
        $admin = $pending->admin();

        if ($admin === null) {
            return $this->redirectRoute('admin.login', navigate: false);
        }

        if ($admin->hasConfirmedTwoFactor()) {
            return $this->redirectRoute('admin.two-factor.challenge', navigate: false);
        }

        $secret = $enrol->begin($admin);
        $this->manualKey = trim(chunk_split($secret, 4, ' '));
        // An <img> data URI rather than inline SVG: no raw HTML in the view.
        $this->qrCodeDataUri = 'data:image/svg+xml;base64,'.base64_encode($twoFactor->qrCodeSvg($admin, $secret));

        return null;
    }

    public function confirm(PendingAdminLogin $pending, EnrolAdminTwoFactor $enrol): mixed
    {
        $this->validate(['code' => 'required|string|max:10']);
        $admin = $this->pendingAdmin($pending);

        if ($admin === null) {
            return $this->redirectRoute('admin.login', navigate: false);
        }

        try {
            $this->recoveryCodes = $enrol->confirm($admin, $this->code);
        } catch (AdminAuthenticationFailed $failure) {
            $this->reset('code');
            $this->addError('code', $failure->getMessage());
        }

        return null;
    }

    public function finish(PendingAdminLogin $pending, CompleteAdminLogin $complete): mixed
    {
        $admin = $this->pendingAdmin($pending);

        if ($admin === null || ! $admin->hasConfirmedTwoFactor()) {
            return $this->redirectRoute('admin.login', navigate: false);
        }

        $complete->handle($admin, request());

        return $this->redirectRoute('admin.dashboard', navigate: false);
    }

    public function render(): View
    {
        return view('livewire.admin.auth.two-factor-setup');
    }

    private function pendingAdmin(PendingAdminLogin $pending): ?AdminUser
    {
        $admin = $pending->admin();

        if ($admin === null) {
            session()->flash('status', AdminAuthenticationFailed::loginExpired()->getMessage());
        }

        return $admin;
    }
}
