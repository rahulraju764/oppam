<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

final class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Horizon's default lets anyone in when APP_ENV=local. Not here: the dashboard is always
     * behind the admin middleware and the viewHorizon gate (AppServiceProvider, system.horizon).
     */
    protected function authorization(): void
    {
        Horizon::auth(fn ($request): bool => Gate::check('viewHorizon', [$request->user('admin')]));
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        // Defined in AppServiceProvider::bootAdminSecurity() (admin guard + system.horizon).
    }
}
