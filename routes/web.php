<?php

declare(strict_types=1);

use App\Http\Controllers\Member\HoroscopeController;
use App\Livewire\Member\Auth\ForgotPassword;
use App\Livewire\Member\Auth\Login;
use App\Livewire\Member\Auth\Register;
use App\Livewire\Member\Auth\VerifyOtp;
use App\Livewire\Member\Onboarding\Submitted;
use App\Livewire\Member\Onboarding\Wizard;
use App\Livewire\Member\Profile\MyProfile;
use App\Livewire\Member\Profile\Show;
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
| Public pages (PRD §6.2, M12), auth (M01) and member pages. Legacy *.php URLs: routes/legacy.php.
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

// Registration, login and password reset (M01, PRD §8.2). Signed-in members are sent on.
Route::middleware('guest')->group(function (): void {
    Route::get('/register', Register::class)->name('register');
    Route::get('/verify-otp', VerifyOtp::class)->name('register.verify');
    Route::get('/login', Login::class)->name('login');
    Route::get('/forgot-password', ForgotPassword::class)->name('password.forgot');
});

// Member area. Pages after onboarding also get `profile.onboarded` (PRD §13); the wizard can't.
Route::middleware(['auth', 'verified.phone'])->group(function (): void {
    Route::get('/onboarding/{step}', Wizard::class)->whereNumber('step')->name('member.onboarding');
    Route::get('/onboarding/submitted', Submitted::class)->name('member.onboarding.submitted');

    // Profiles (M03): own (/me) and others' (/profile/OPM…). Onboarded members only.
    Route::middleware('profile.onboarded')->group(function (): void {
        Route::get('/me', MyProfile::class)->name('member.profile.me');
        Route::get('/profile/{profile}', Show::class)->where('profile', 'OPM[0-9]+')->name('member.profile.show');
    });

    // Horoscope file (M11): short-lived signed link from HoroscopeAccess; checked + audited again.
    Route::get('/media/horoscope/{profile}', HoroscopeController::class)
        ->middleware('signed')->where('profile', 'OPM[0-9]+')->name('member.horoscope');
});

// Living styleguide for visual checks (P0.2). Local only: never registered in testing/production.
if (app()->environment('local')) {
    Route::view('/styleguide', 'pages.styleguide.index')->name('styleguide');
    Route::view('/styleguide/member', 'pages.styleguide.member')->name('styleguide.member');
}
