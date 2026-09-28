<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin panel — admin.oppam.in (config('oppam.admin_domain'))
|--------------------------------------------------------------------------
| Loaded by bootstrap/app.php inside: middleware('web'), domain(admin), name('admin.').
| P0.5 adds the `admin` guard, mandatory 2FA and IP allowlist middleware (PRD §8, A01).
| Every page is a Livewire full-page component under App\Livewire\Admin.
*/

// Placeholder until P0.5 builds admin auth. Deliberately returns 404 so nothing is exposed.
Route::fallback(fn () => abort(404))->name('fallback');
