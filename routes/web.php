<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public + member site — oppam.in
|--------------------------------------------------------------------------
| Public pages are converted from the template in P0.3; member pages arrive from P1.1.
*/

Route::view('/', 'welcome')->name('home');

// Living styleguide for visual checks (P0.2). Local only: never registered in testing/production.
if (app()->environment('local')) {
    Route::view('/styleguide', 'pages.styleguide.index')->name('styleguide');
    Route::view('/styleguide/member', 'pages.styleguide.member')->name('styleguide.member');
}
