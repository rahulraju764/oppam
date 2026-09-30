<?php

declare(strict_types=1);

namespace App\Livewire\Member\Auth;

use App\Actions\Auth\LogoutMember;
use App\Actions\Auth\LogoutOtherDevices;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * "Logout" and "Log out other devices" in the member header menu (M01). Both are POSTs through
 * Livewire (CSRF-protected), never GET links. The signed-in user comes from the session only.
 */
final class LogoutButton extends Component
{
    public ?string $notice = null;

    public function logout(LogoutMember $logout): mixed
    {
        $logout->handle();

        return $this->redirectRoute('home', navigate: false);
    }

    public function logoutOtherDevices(LogoutOtherDevices $logoutOthers): void
    {
        $user = auth('web')->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $logoutOthers->handle($user, request());

        // Shown inline (role="status"): the member toast stack arrives with notifications (P3.2).
        $this->notice = __('You have been logged out on all other devices.');
    }

    public function render(): View
    {
        return view('livewire.member.auth.logout-button');
    }
}
