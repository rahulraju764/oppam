<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // Explicit registration order matters (PRD §5.1, §13):
        // the admin DOMAIN group must be registered before the host-less public routes,
        // otherwise a request to admin.oppam.in/ would match the public home page.
        using: function (): void {
            Route::middleware('web')
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

            // Uptime probe for load balancers / monitors — deliberately host-agnostic (probed by IP).
            Route::get('/up', fn () => response('OK'))->name('health');
        },
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
