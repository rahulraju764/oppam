<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Auth;

use App\Actions\Admin\Auth\LogoutAdmin;
use App\Models\AdminUser;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** "Sign out" in the admin top bar: a POST through Livewire (CSRF-protected), never a GET link. */
final class LogoutButton extends Component
{
    public function logout(LogoutAdmin $logout): mixed
    {
        $admin = auth('admin')->user();

        if ($admin instanceof AdminUser) {
            $logout->handle($admin, request());
        }

        return $this->redirectRoute('admin.login', navigate: false);
    }

    public function render(): View
    {
        return view('livewire.admin.auth.logout-button');
    }
}
