<?php

declare(strict_types=1);

use App\Livewire\Public\About;
use App\Livewire\Public\Branches;
use App\Livewire\Public\Contact;
use App\Livewire\Public\Home;
use App\Livewire\Public\Plans;
use App\Livewire\Public\SuccessStories;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public + member site — oppam.in
|--------------------------------------------------------------------------
| Public pages (PRD §6.2, M12); member pages arrive from P1.1. Legacy *.php URLs: routes/legacy.php.
*/

Route::get('/', Home::class)->name('home');
Route::get('/about', About::class)->name('about');
Route::get('/branches', Branches::class)->name('branches');
Route::get('/success-stories', SuccessStories::class)->name('success-stories');
Route::get('/plans', Plans::class)->name('plans');
Route::get('/contact', Contact::class)->name('contact');
Route::view('/privacy', 'pages.privacy')->name('privacy');
Route::view('/terms', 'pages.terms')->name('terms');

// The FAQ lives on the plans page (template faq.php was a 301 stub to package#faq).
Route::permanentRedirect('/faq', '/plans#faq')->name('faq');

// Living styleguide for visual checks (P0.2). Local only: never registered in testing/production.
if (app()->environment('local')) {
    Route::view('/styleguide', 'pages.styleguide.index')->name('styleguide');
    Route::view('/styleguide/member', 'pages.styleguide.member')->name('styleguide.member');
}
