<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\MasterExportController;
use App\Http\Controllers\Admin\MemberExportController;
use App\Livewire\Admin\Auth\AcceptInvitation;
use App\Livewire\Admin\Auth\Login;
use App\Livewire\Admin\Auth\TwoFactorChallenge;
use App\Livewire\Admin\Auth\TwoFactorSetup;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Masters\Index as MastersIndex;
use App\Livewire\Admin\Masters\ListEditor as MastersListEditor;
use App\Livewire\Admin\Members\Index as MembersIndex;
use App\Livewire\Admin\Members\Show as MembersShow;
use App\Livewire\Admin\Moderation\EditedFieldsQueue;
use App\Livewire\Admin\Moderation\Escalations;
use App\Livewire\Admin\Moderation\PhotoQueueGrid;
use App\Livewire\Admin\Moderation\ProfileQueue;
use App\Livewire\Admin\Moderation\ProfileReview;
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

    // Members (A03). Viewing needs members.view; every action re-checks its own permission.
    Route::middleware('can:members.view')->prefix('members')->name('members.')->group(function (): void {
        Route::get('/', MembersIndex::class)->name('index');
        // CSV download: a short-lived signed link built by the list (ExportMembers checks members.export).
        Route::get('/export', MemberExportController::class)->middleware('signed')->name('export');
        Route::get('/{profile}', MembersShow::class)->where('profile', 'OPM[0-9]+')->name('show');
    });

    // Master data (A11). Viewing needs masters.view; every change re-checks masters.edit.
    Route::middleware('can:masters.view')->prefix('masters')->name('masters.')->group(function (): void {
        Route::get('/', MastersIndex::class)->name('index');
        Route::get('/{list}', MastersListEditor::class)->where('list', '[a-z0-9-]+')->name('edit');
        Route::get('/{list}/export', MasterExportController::class)->where('list', '[a-z0-9-]+')->name('export');
    });

    // Moderation (A04). Viewing needs moderation.view; every decision re-checks moderation.act.
    Route::middleware('can:moderation.view')->prefix('moderation')->name('moderation.')->group(function (): void {
        Route::get('/profiles', ProfileQueue::class)->name('profiles');
        Route::get('/profiles/{profile}', ProfileReview::class)->where('profile', 'OPM[0-9]+')->name('profiles.review');
        Route::get('/photos', PhotoQueueGrid::class)->name('photos');
        Route::get('/edits', EditedFieldsQueue::class)->name('edits');
        Route::get('/escalations', Escalations::class)->name('escalations');
    });
});

Route::fallback(fn () => abort(404))->name('fallback');
