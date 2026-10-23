<?php

declare(strict_types=1);

use App\Livewire\Broker\Apply;
use App\Livewire\Broker\Auth\VerifyDevice;
use App\Livewire\Broker\ComingSoon;
use App\Livewire\Broker\Dashboard;
use App\Livewire\Broker\Kyc;
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

Route::middleware(['auth', 'role:BROKER', 'broker.active', 'broker.staff.active'])->group(function (): void {
    Route::get('/verify-device', VerifyDevice::class)->middleware('throttle:30,1')->name('device');

    Route::middleware('broker.device')->group(function (): void {
        Route::get('/', Dashboard::class)->middleware('bureau:bureau.dashboard.view')->name('dashboard');
        Route::get('/kyc', Kyc::class)->middleware('bureau:kyc.manage')->name('kyc');

        // B.11 screens of later sessions, registered now with their real gate (decisions 2026-10-23):
        // P7.3 / P7.6 swap the component.
        Route::get('/profiles/create', ComingSoon::class)->middleware('bureau:profiles.create')->name('profiles.create');
        Route::get('/import', ComingSoon::class)->middleware('bureau:profiles.import')->name('import');
    });
});
