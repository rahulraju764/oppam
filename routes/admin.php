<?php

declare(strict_types=1);

use App\Livewire\Admin\Auth\AcceptInvitation;
use App\Livewire\Admin\Auth\Login;
use App\Livewire\Admin\Auth\TwoFactorChallenge;
use App\Livewire\Admin\Auth\TwoFactorSetup;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Roles\Editor as RolesEditor;
use App\Livewire\Admin\Sessions\Index as SessionsIndex;
use App\Livewire\Admin\Staff\Index as StaffIndex;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin panel — admin.oppam.in (config('oppam.admin_domain'))
|--------------------------------------------------------------------------
| Loaded by bootstrap/app.php inside: middleware(['web', 'ip.allowlist']), domain(admin),
| name('admin.'). Every page is a Livewire full-page component under App\Livewire\Admin; every
| data-changing action authorizes again inside its Action (a hidden link is not security).
*/

// Sign-in flow (A01). Password → (first time) 2FA enrolment | 2FA challenge → panel.
Route::middleware('guest:admin')->group(function (): void {
    Route::get('/login', Login::class)->name('login');
    Route::get('/two-factor', TwoFactorChallenge::class)->name('two-factor.challenge');
    Route::get('/two-factor/setup', TwoFactorSetup::class)->name('two-factor.setup');
    Route::get('/invitations/{token}', AcceptInvitation::class)->where('token', '[A-Za-z0-9]{64}')->name('invitations.accept');
});

// The panel: signed in, live registered session, active account, 2FA passed (PRD §11.0).
Route::middleware(['auth:admin', 'admin.session', 'admin.active', '2fa.confirmed'])->group(function (): void {
    Route::get('/', Dashboard::class)->name('dashboard');
    Route::get('/staff', StaffIndex::class)->middleware('can:staff.view')->name('staff.index');
    Route::get('/roles', RolesEditor::class)->middleware('can:roles.view')->name('roles.edit');
    Route::get('/sessions', SessionsIndex::class)->name('sessions.index');
});

Route::fallback(fn () => abort(404))->name('fallback');
