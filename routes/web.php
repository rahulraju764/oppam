<?php

declare(strict_types=1);

use App\Http\Controllers\Member\ChatImageController;
use App\Http\Controllers\Member\CreditNoteDownloadController;
use App\Http\Controllers\Member\DownloadDataExportController;
use App\Http\Controllers\Member\HoroscopeController;
use App\Http\Controllers\Member\ImpersonationController;
use App\Http\Controllers\Member\InvoiceDownloadController;
use App\Http\Controllers\Member\UnsubscribeSavedSearchController;
use App\Http\Controllers\Public\RobotsController;
use App\Http\Controllers\Public\SitemapController;
use App\Livewire\Member\Activity\Visitors;
use App\Livewire\Member\Auth\ClaimProfile;
use App\Livewire\Member\Auth\ForgotPassword;
use App\Livewire\Member\Auth\Login;
use App\Livewire\Member\Auth\Register;
use App\Livewire\Member\Auth\ResetPasswordEmail;
use App\Livewire\Member\Auth\RestoreAccount;
use App\Livewire\Member\Auth\VerifyOtp;
use App\Livewire\Member\Billing\Checkout;
use App\Livewire\Member\Billing\CheckoutSuccess;
use App\Livewire\Member\Billing\History;
use App\Livewire\Member\Browse\AllProfiles;
use App\Livewire\Member\Chat\Messages;
use App\Livewire\Member\Dashboard\Dashboard;
use App\Livewire\Member\Engagement\Favorites;
use App\Livewire\Member\Engagement\Interests;
use App\Livewire\Member\Engagement\Likes;
use App\Livewire\Member\Legal\AcceptTerms;
use App\Livewire\Member\Matches\Daily;
use App\Livewire\Member\Matches\MyMatches;
use App\Livewire\Member\Notifications\Index as NotificationsIndex;
use App\Livewire\Member\Onboarding\Submitted;
use App\Livewire\Member\Onboarding\Wizard;
use App\Livewire\Member\Profile\MyProfile;
use App\Livewire\Member\Profile\Show;
use App\Livewire\Member\Search\Search;
use App\Livewire\Member\Settings\Settings as MemberSettings;
use App\Livewire\Member\Settings\VerifyEmail;
use App\Livewire\Member\Support\Help;
use App\Livewire\Member\Support\TicketThread;
use App\Livewire\Member\Verification\Upload as VerificationUpload;
use App\Livewire\Public\About;
use App\Livewire\Public\AdPreview;
use App\Livewire\Public\Branches;
use App\Livewire\Public\CommunityLanding;
use App\Livewire\Public\Contact;
use App\Livewire\Public\Home;
use App\Livewire\Public\LegalPage;
use App\Livewire\Public\Plans;
use App\Livewire\Public\SafetyCentre;
use App\Livewire\Public\SuccessStories;
use App\Models\CommunityPage;
use Illuminate\Broadcasting\BroadcastController;
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
Route::get('/safety', SafetyCentre::class)->name('safety');
// Versioned legal pages (A08, P8.1b): the live version of each kind.
Route::get('/privacy', LegalPage::class)->defaults('kind', 'PRIVACY')->name('privacy');
Route::get('/terms', LegalPage::class)->defaults('kind', 'TERMS')->name('terms');
Route::get('/grievance', LegalPage::class)->defaults('kind', 'GRIEVANCE')->name('grievance');

// A08 signed preview (P8.1a): the real pages with one unpublished row. Issued by IssueContentPreview.
Route::middleware(['signed', 'throttle:60,1'])->prefix('content-preview')->name('content.preview.')->group(function (): void {
    Route::get('/home/{code}', Home::class)->middleware('content.preview:banners,testimonials,home-blocks')->name('home');
    Route::get('/about/{code}', About::class)->middleware('content.preview:home-blocks')->name('about');
    Route::get('/story/{code}', SuccessStories::class)->middleware('content.preview:stories')->name('story');
    Route::get('/branch/{code}', Branches::class)->middleware('content.preview:branches')->name('branch');
    Route::get('/faq/{code}', Plans::class)->middleware('content.preview:faq')->name('faq');
    Route::get('/legal/{code}', LegalPage::class)->middleware('content.preview:legal')->name('legal');
    Route::get('/community/{code}', CommunityLanding::class)->middleware('content.preview:communities')->name('community');
    Route::get('/ad/{code}', AdPreview::class)->middleware('content.preview:ads')->name('ad');
});

// The FAQ lives on the plans page (template faq.php was a 301 stub to package#faq).
Route::permanentRedirect('/faq', '/plans#faq')->name('faq');

// Registration, login and password reset (M01, PRD §8.2). Signed-in members are sent on.
Route::middleware('guest')->group(function (): void {
    Route::get('/register', Register::class)->name('register');
    Route::get('/verify-otp', VerifyOtp::class)->name('register.verify');
    Route::get('/login', Login::class)->name('login');
    Route::get('/forgot-password', ForgotPassword::class)->name('password.forgot');
    // P8.2c (M14): after the right password / code for an account the member closed, "restore it?".
    Route::get('/account/restore', RestoreAccount::class)->name('member.account.restore');
    // P8.2b: the email-link reset (PRD §8.2 method 3); the token is checked when the form is sent.
    Route::get('/reset-password/{token}', ResetPasswordEmail::class)
        ->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:30,1')->name('password.reset.email');
});

// M14 (P8.2b): an email confirmation link — signed in or not, the link is the proof; a click confirms.
Route::get('/settings/email/verify/{token}', VerifyEmail::class)
    ->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:30,1')->name('member.email.verify');

// The first Phase 2 pass used /all-profiles; PRD §6.2 says /profiles (decisions 2026-10-04).
Route::permanentRedirect('/all-profiles', '/profiles');
Route::permanentRedirect('/my-matches', '/matches');
Route::permanentRedirect('/daily-matches', '/matches/daily');

// Admin impersonation (A01 / A03, P1.7b): the admin panel hands the browser over with a single-use
// token (60 s, same IP); "End session" in the banner closes it. Not behind `guest`: an existing
// member session in that browser is ended by the handoff (this device only).
Route::get('/impersonate/{token}', [ImpersonationController::class, 'enter'])
    ->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:10,1')->name('impersonation.enter');
Route::post('/impersonation/end', [ImpersonationController::class, 'end'])->middleware('auth')->name('impersonation.end');
Route::view('/impersonation/ended', 'pages.impersonation-ended')->name('impersonation.ended');

// Member area. Pages after onboarding also get `profile.onboarded` (PRD §13); the wizard can't.
// Forced re-acceptance (A08, P8.1b): outside `legal.accepted`, which sends members here.
Route::get('/legal/accept', AcceptTerms::class)->middleware('auth')->name('legal.accept');

Route::middleware(['auth', 'verified.phone', 'legal.accepted'])->group(function (): void {
    // §11A B.6 claim flow: "Is this you?" straight after the registration OTP (one-time session flag).
    Route::get('/register/claim', ClaimProfile::class)->middleware('throttle:30,1')->name('register.claim');
    Route::get('/onboarding/{step}', Wizard::class)->whereNumber('step')->name('member.onboarding');
    Route::get('/onboarding/submitted', Submitted::class)->name('member.onboarding.submitted');

    // Profiles (M03): own (/me) and others' (/profile/OPM…). Onboarded members only.
    Route::middleware('profile.onboarded')->group(function (): void {
        Route::get('/dashboard', Dashboard::class)->name('member.dashboard');
        Route::get('/me', MyProfile::class)->name('member.profile.me');
        Route::get('/matches', MyMatches::class)->name('member.matches');
        Route::get('/matches/daily', Daily::class)->name('member.matches.daily');
        Route::get('/visitors', Visitors::class)->name('member.visitors');
        Route::get('/notifications', NotificationsIndex::class)->name('member.notifications');
        Route::get('/likes', Likes::class)->name('member.likes');
        Route::get('/favorites', Favorites::class)->name('member.favorites');
        Route::get('/interests', Interests::class)->name('member.interests');
        Route::get('/messages', Messages::class)->name('member.messages');
        Route::get('/search', Search::class)->name('member.search');
        Route::get('/profiles', AllProfiles::class)->name('member.profiles');
        Route::get('/profile/{profile}', Show::class)->where('profile', 'OPM[0-9]+')->name('member.profile.show');

        // Membership (M10, F04): checkout by plan KEY (+ ?duration=), the success page polls the order.
        Route::get('/billing', History::class)->name('member.billing');

        // ID verification (M09): upload + status; documents are never served back to the member.
        Route::get('/verify', VerificationUpload::class)->name('member.verification');
        Route::get('/checkout/{plan}', Checkout::class)->where('plan', '[a-z]{3,10}')->name('member.checkout');
        Route::get('/billing/success/{order}', CheckoutSuccess::class)->where('order', 'ORD[0-9]+')->name('member.billing.success');
        Route::get('/billing/invoices/{invoice}', InvoiceDownloadController::class)
            ->where('invoice', 'OPM-[0-9]{4}-[0-9]{6}')->middleware('throttle:30,1')->name('member.billing.invoice');
        Route::get('/billing/credit-notes/{number}', CreditNoteDownloadController::class)
            ->where('number', 'CN-[0-9]{4}-[0-9]{6}')->middleware('throttle:30,1')->name('member.billing.credit-note');

        // Settings (M14): privacy, notifications, blocked & ignored, hide/delete — tabs in one page (?tab=).
        Route::get('/settings', MemberSettings::class)->name('member.settings');
        Route::redirect('/settings/blocked', '/settings?tab=blocked')->name('member.settings.blocked');
        Route::get('/settings/data-export/{code}', DownloadDataExportController::class)
            ->where('code', 'DEX[A-Z0-9]{10}')->middleware('throttle:30,1')->name('member.settings.data-export.download');

        // Safety & support (M09, A10): in-app help and own requests only.
        Route::get('/help', Help::class)->name('member.help');
        Route::get('/help/{ticket}', TicketThread::class)->where('ticket', 'TKT[0-9]+')->name('member.help.ticket');
    });

    // WebSocket channel sign-in for the member site (PRD §9): routes/channels.php decides per channel.
    Route::post('/broadcasting/auth', [BroadcastController::class, 'authenticate'])->name('broadcasting.auth');

    // Chat image (M07, P4.2): private file, re-authorised on every request (participants only).
    Route::get('/media/chat/{message}/{size}', ChatImageController::class)
        ->where('message', '[0-9A-Za-z]{26}')->whereIn('size', ['preview', 'original'])
        ->middleware('throttle:120,1')->name('member.chat.image');

    // Horoscope file (M11): short-lived signed link from HoroscopeAccess; checked + audited again.
    Route::get('/media/horoscope/{profile}', HoroscopeController::class)
        ->middleware('signed')->where('profile', 'OPM[0-9]+')->name('member.horoscope');
});

// Saved-search alert unsubscribe (M04): the email's random token, no sign-in. GET confirms, POST
// (also the RFC 8058 one-click target, CSRF-exempt in bootstrap/app.php) turns the alerts off.
Route::middleware('throttle:30,1')->group(function (): void {
    Route::get('/saved-searches/unsubscribe/{token}', [UnsubscribeSavedSearchController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{48}')->name('saved-searches.unsubscribe');
    Route::post('/saved-searches/unsubscribe/{token}', [UnsubscribeSavedSearchController::class, 'store'])
        ->where('token', '[A-Za-z0-9]{48}');
});

// SEO (M12, P8.1b): robots and sitemap follow A15 `seo.indexable`.
Route::get('/robots.txt', RobotsController::class)->name('robots');
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');

// Community landing pages (M12, A08), e.g. /nair-matrimony. Only addresses shaped like one, and only
// published pages (404 otherwise). Last, so no other route is ever shadowed.
Route::get('/{community}', CommunityLanding::class)->where('community', CommunityPage::SLUG_PATTERN)
    ->middleware('throttle:60,1')->name('community');

// Living styleguide for visual checks (P0.2). Local only: never registered in testing/production.
if (app()->environment('local')) {
    Route::view('/styleguide', 'pages.styleguide.index')->name('styleguide');
    Route::view('/styleguide/member', 'pages.styleguide.member')->name('styleguide.member');
}
