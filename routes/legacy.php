<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Legacy template URLs → 301 (PRD §6.1)
|--------------------------------------------------------------------------
| The PHP template served /about.php, /package.php … and its .htaccess 301'd the .php form to
| the extensionless one. Any of those links still in the wild land on the new named routes.
| Targets are PATHS, not route names, so pages that are not built yet (member area) redirect
| to where they will live; they 404 until then. Unknown *.php → normal 404.
*/

$legacy = [
    'index' => '/',
    'about' => '/about',
    'branches' => '/branches',
    'success-stories' => '/success-stories',
    'package' => '/plans',
    'faq' => '/plans#faq',
    'contact' => '/contact',
    'privacy' => '/privacy',
    'terms' => '/terms',
    'login' => '/login',
    'register' => '/register',
    'forgot-password' => '/forgot-password',
    'dashboard' => '/dashboard',
    'my-profile' => '/me',
    'all-profiles' => '/profiles',
    'search' => '/search',
    'my-matches' => '/matches',
    'daily-matches' => '/matches/daily',
    'interest' => '/interests',
    'messages' => '/messages',
    'notifications' => '/notifications',
    'verification' => '/verify',
    'checkout' => '/plans',
    // single-profile.php?id=<pid> used the template's demo key, which maps to no real profile.
    'single-profile' => '/profiles',
    // The six wizard pages → the one wizard route (M02, P1.2).
    'profile-creation' => '/onboarding/1',
    'education' => '/onboarding/2',
    'family' => '/onboarding/3',
    'partner' => '/onboarding/4',
    'contact-details' => '/onboarding/5',
    'profile-photos' => '/onboarding/6',
];

Route::get('/index', fn () => redirect('/', 301));

Route::get('/{page}.php', function (string $page) use ($legacy) {
    abort_unless(array_key_exists($page, $legacy), 404);

    return redirect($legacy[$page], 301);
})->where('page', '[a-z-]+')->name('legacy');
