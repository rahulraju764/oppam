<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;
use Laravel\Dusk\DuskServiceProvider;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Passkeys;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Fortify is used ONLY for admin 2FA (PRD §8.1, configured in P0.5). Its default
        // public account routes (/login, /register, /reset-password, /user/*, passkeys)
        // would bypass our own OTP-based member auth, so none of them may be registered.
        Fortify::ignoreRoutes();
        Passkeys::ignoreRoutes();

        // Dusk exposes /_dusk/login/{userId} (log in as ANY user) in every non-production
        // environment — including staging. It is excluded from package discovery in
        // composer.json and registered here for local and testing only.
        if ($this->app->environment('local', 'testing')) {
            $this->app->register(DuskServiceProvider::class);
        }
    }

    public function boot(): void
    {
        // Catch N+1 queries, silently discarded attributes and missing attributes
        // everywhere except production (oppam-code-standards: Eloquent rules).
        Model::shouldBeStrict(! $this->app->isProduction());

        // $paginator->links() renders the template's pagination bar (x-ui.pagination).
        Paginator::defaultView('vendor.pagination.oppam');
    }
}
