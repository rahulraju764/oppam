<?php

declare(strict_types=1);

use App\Http\Middleware\CaptureReferralCode;
use App\Http\Middleware\ConfigureAdminSession;
use App\Http\Middleware\EnsureAdminIpAllowed;
use App\Http\Middleware\EnsureAdminIsActive;
use App\Http\Middleware\EnsureAdminSessionIsValid;
use App\Http\Middleware\EnsureLivewireComponentHost;
use App\Http\Middleware\EnsureMemberSessionIsValid;
use App\Http\Middleware\EnsurePhoneIsVerified;
use App\Http\Middleware\EnsureProfileOnboarded;
use App\Http\Middleware\EnsureTwoFactorConfirmed;
use App\Support\Navigation\MemberLanding;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // Explicit registration order matters (PRD §5.1, §13):
        // the admin DOMAIN group must be registered before the host-less public routes,
        // otherwise a request to admin.oppam.in/ would match the public home page.
        using: function (): void {
            // ip.allowlist covers the whole admin domain, the login page included (PRD §8.1).
            Route::middleware(['web', 'ip.allowlist'])
                ->domain(config('oppam.admin_domain'))
                ->name('admin.')
                ->group(base_path('routes/admin.php'));

            // Signature-verified, stateless, no CSRF (PRD §13 Webhooks).
            Route::prefix('webhooks')
                ->name('webhooks.')
                ->group(base_path('routes/webhooks.php'));

            // Public site and broker portal are pinned to the main host, so they can never be
            // served on the admin domain (or any other Host header).
            Route::middleware('web')
                ->domain(config('oppam.app_domain'))
                ->prefix('broker')
                ->name('broker.')
                ->group(base_path('routes/broker.php'));

            Route::middleware('web')
                ->domain(config('oppam.app_domain'))
                ->group(base_path('routes/web.php'));

            // 301s from the PHP template's *.php URLs (PRD §6.1). No session needed.
            Route::domain(config('oppam.app_domain'))
                ->group(base_path('routes/legacy.php'));

            // Uptime probe for load balancers / monitors — deliberately host-agnostic (probed by IP).
            Route::get('/up', fn () => response('OK'))->name('health');
        },
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Before StartSession: the admin domain gets its own session cookie (PRD §8.1).
        $middleware->web(prepend: [ConfigureAdminSession::class]);

        // Livewire's single update endpoint serves both hosts: admin components may only run on
        // the admin domain, and nothing else may run there (P0.5 review Blocker).
        $middleware->web(append: [EnsureLivewireComponentHost::class]);

        // Members/brokers: a suspended account or a session from before "log out other devices"
        // is signed out on its next request, pages and Livewire updates alike (M01, R-M01-5).
        // ?ref=BRK… sets the 30-day first-touch referral cookie (R-M13-9).
        $middleware->web(append: [EnsureMemberSessionIsValid::class, CaptureReferralCode::class]);

        $middleware->alias([
            'admin.session' => EnsureAdminSessionIsValid::class,
            'admin.active' => EnsureAdminIsActive::class,
            '2fa.confirmed' => EnsureTwoFactorConfirmed::class,
            'ip.allowlist' => EnsureAdminIpAllowed::class,
            'verified.phone' => EnsurePhoneIsVerified::class,
            'profile.onboarded' => EnsureProfileOnboarded::class,
        ]);

        // Guests go to the login page of the surface they tried to reach.
        $middleware->redirectGuestsTo(fn (Request $request): string => $request->getHost() === config('oppam.admin_domain')
            ? route('admin.login')
            : route('login'));

        // Signed-in members who open /login or /register go where they belong (wizard or home).
        $middleware->redirectUsersTo(fn (Request $request): string => $request->getHost() === config('oppam.admin_domain')
            ? route('admin.dashboard')
            : app(MemberLanding::class)->url($request->user('web')));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
