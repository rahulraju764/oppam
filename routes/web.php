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
