<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Auth;

use App\Actions\Admin\Staff\AcceptAdminInvitation;
use App\Exceptions\Admin\AdminAuthenticationFailed;
use App\Exceptions\Admin\GuardrailViolation;
use App\Models\AdminInvitation;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Staff invitation landing page (A01): set a password, then sign in (and enrol 2FA). The token
 * comes from the emailed link and is #[Locked]; an invalid/expired/used link shows a message.
 */
#[Layout('layouts::admin-auth', ['title' => 'Accept invitation'])]
final class AcceptInvitation extends Component
{
    #[Locked]
    public string $token = '';

    #[Locked]
    public ?string $email = null;

    #[Locked]
    public ?string $name = null;

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $invitation = AcceptAdminInvitation::findUsable($token);

        if ($invitation instanceof AdminInvitation) {
            $this->email = $invitation->email;
            $this->name = $invitation->name;
        }
    }

    public function accept(AcceptAdminInvitation $accept): mixed
    {
        $this->validate(['password' => ['required', 'confirmed', Password::defaults()]]);

        try {
            $accept->handle($this->token, $this->password);
        } catch (AdminAuthenticationFailed|GuardrailViolation $failure) {
            $this->addError('password', $failure->getMessage());

            return null;
        }

        session()->flash('status', __('Your account is ready. Sign in to set up two-factor authentication.'));

        return $this->redirectRoute('admin.login', navigate: false);
    }

    public function render(): View
    {
        return view('livewire.admin.auth.accept-invitation');
    }
}
