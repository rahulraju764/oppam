<?php

declare(strict_types=1);

use App\Http\Controllers\Broker\ManagedProfileExportController;
use App\Http\Controllers\Broker\MaterialsController;
use App\Http\Controllers\Broker\ReferralCodeController;
use App\Livewire\Broker\Activity;
use App\Livewire\Broker\Apply;
use App\Livewire\Broker\Auth\VerifyDevice;
use App\Livewire\Broker\ComingSoon;
use App\Livewire\Broker\Dashboard;
use App\Livewire\Broker\Kyc;
use App\Livewire\Broker\ManagedProfiles;
use App\Livewire\Broker\Materials;
use App\Livewire\Broker\Referrals;
use App\Livewire\Broker\Staff;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Broker / bureau portal — oppam.in/broker (PRD §11A B.11)
|--------------------------------------------------------------------------
| Loaded by bootstrap/app.php inside: middleware('web'), prefix('broker'), name('broker.').
| The portal: auth + role:BROKER + broker.active + broker.staff.active (§8.1), the new-device OTP
| for Owners / Managers (broker.device), and on every route its B.11 "Min. permission" through
| BureauGate (`bureau:<key>`) — which also applies the KYC gate to create / import / export
| (403 BROKER_KYC_REQUIRED). Components and Actions check the permission again. These middleware
| are persistent: Livewire updates are checked the same way (AppServiceProvider).
*/

// Public application (B.3), behind the broker.self_apply flag (the component 404s without it).
Route::get('/apply', Apply::class)->middleware(['guest', 'throttle:30,1'])->name('apply');

// Referral code check (B.5): {valid, name, district} only — public, for the registration / wizard hint.
Route::get('/validate', ReferralCodeController::class)->middleware('throttle:30,1')->name('validate');

// Staff invitation acceptance (B.8, P7.5): guests with the texted link; the token is matched by its hash.
Route::get('/staff/invite/{token}', Staff\AcceptInvite::class)->middleware(['guest', 'throttle:30,1'])
    ->where('token', '[A-Za-z0-9]{64}')->name('staff.accept');

Route::middleware(['auth', 'role:BROKER', 'broker.active', 'broker.staff.active'])->group(function (): void {
    Route::get('/verify-device', VerifyDevice::class)->middleware('throttle:30,1')->name('device');

    Route::middleware('broker.device')->group(function (): void {
        Route::get('/', Dashboard::class)->middleware('bureau:bureau.dashboard.view')->name('dashboard');
        Route::get('/kyc', Kyc::class)->middleware('bureau:kyc.manage')->name('kyc');

        // Staff accounts & activity (B.8, P7.5): staff pages are the Owner's (`staff.manage`); the
        // activity log is Owner + Manager (`activity.view`); earnings are the Owner's (P7.8 swaps the page).
        Route::prefix('staff')->name('staff.')->middleware('bureau:staff.manage')->group(function (): void {
            Route::get('/', Staff\Index::class)->name('index');
            Route::get('/{code}', Staff\Show::class)->where('code', 'STF[0-9]+')->name('show');
        });
        Route::get('/activity', Activity::class)->middleware('bureau:activity.view')->name('activity');
        Route::get('/earnings', ComingSoon::class)->middleware('bureau:earnings.view')->name('earnings');

        // Referral program (B.5, P7.2): own referrals only; materials are the caller's own bureau.
        Route::get('/referrals', Referrals::class)->middleware('bureau:referrals.view')->name('referrals');
        Route::get('/materials', Materials::class)->middleware('bureau:materials.view')->name('materials');
        Route::middleware(['bureau:materials.view', 'throttle:20,1'])->prefix('materials')->name('materials.')
            ->controller(MaterialsController::class)->group(function (): void {
                Route::get('/qr.svg', 'qrSvg')->name('qr.svg');
                Route::get('/qr.png', 'qrPng')->name('qr.png');
                Route::get('/poster.pdf', 'poster')->name('poster');
            });

        // Managed profiles (B.6, P7.3): always the caller's ManagedProfileScope — a foreign or
        // unassigned code is 404 (R-M13-6 / R-M13-20).
        Route::prefix('profiles')->name('profiles.')->group(function (): void {
            Route::get('/', ManagedProfiles\Index::class)->middleware('bureau:profiles.view')->name('index');
            Route::get('/create', ManagedProfiles\Form::class)->middleware('bureau:profiles.create')->name('create');
            Route::get('/{code}', ManagedProfiles\Show::class)->middleware('bureau:profiles.view')->where('code', 'OPM[0-9]+')->name('show');
            Route::get('/{code}/edit', ManagedProfiles\Form::class)->middleware('bureau:profiles.edit')->where('code', 'OPM[0-9]+')->name('edit');
            Route::get('/{code}/notes', ManagedProfiles\Notes::class)->middleware('bureau:client_notes.write')->where('code', 'OPM[0-9]+')->name('notes');
            Route::get('/{code}/inbox', ManagedProfiles\ClientInbox::class)->middleware('bureau:client_inbox.respond')->where('code', 'OPM[0-9]+')->name('inbox');

            // Exports (B.7, P7.4): no `bureau:` or `throttle` middleware on purpose — ExportManagedProfile
            // checks `profiles.export`, ownership and its hourly cap itself, so that EVERY attempt is
            // logged (R-M13-13); a middleware refusal would leave no row.
            Route::controller(ManagedProfileExportController::class)->where(['code' => 'OPM[0-9]+'])
                ->group(function (): void {
                    Route::get('/{code}/export/biodata', 'biodata')->name('export.biodata');
                    Route::get('/{code}/export/photos', 'photos')->name('export.photos');
                });
        });

        // B.11 screens of later sessions, registered now with their real gate (decisions 2026-10-23):
        // P7.6 swaps the component.
        Route::get('/import', ComingSoon::class)->middleware('bureau:profiles.import')->name('import');
    });
});
