<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\EnsureAdminIpAllowed;
use App\Http\Middleware\EnsureAdminIsActive;
use App\Http\Middleware\EnsureAdminSessionIsValid;
use App\Http\Middleware\EnsureTwoFactorConfirmed;
use App\Models\AdminUser;
use App\Models\Masters\Caste;
use App\Models\Masters\Country;
use App\Models\Masters\District;
use App\Models\Masters\Education;
use App\Models\Masters\IncomeBand;
use App\Models\Masters\MasterOption;
use App\Models\Masters\MotherTongue;
use App\Models\Masters\Occupation;
use App\Models\Masters\Rasi;
use App\Models\Masters\Religion;
use App\Models\Masters\Star;
use App\Models\Masters\State;
use App\Observers\MasterDataObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Dusk\DuskServiceProvider;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Passkeys;
use Livewire\Livewire;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

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

        // Any master-data write invalidates the cached lists (PRD A11). Registered per concrete
        // model: Eloquent fires events under the concrete class name, not the abstract base.
        foreach ([Religion::class, Caste::class, Star::class, Rasi::class, Country::class, State::class, District::class,
            Education::class, Occupation::class, IncomeBand::class, MotherTongue::class, MasterOption::class] as $master) {
            $master::observe(MasterDataObserver::class);
        }

        $this->bootAdminSecurity();
    }

    /** Admin panel security wiring (PRD §8.1, §8.4, A01). */
    private function bootAdminSecurity(): void
    {
        // A suspended or locked admin holds NO permission (every Action re-checks via Gate), and
        // super_admin holds every permission, including ones added after the role was seeded.
        Gate::before(function (mixed $user, string $ability): ?bool {
            if (! $user instanceof AdminUser) {
                return null;
            }

            if (! $user->isActive() || $user->isLocked()) {
                return false;
            }

            if ($user->isSuperAdmin()) {
                return true;
            }

            try {
                return $user->hasPermissionTo($ability, 'admin') ? true : null;
            } catch (PermissionDoesNotExist) {
                return null;   // not a permission key: let a defined gate/policy decide
            }
        });

        // Horizon and Pulse live on the admin domain behind the admin middleware (config) and
        // these permissions — in every environment, local included.
        Gate::define('viewHorizon', fn (mixed $user = null): bool => $user instanceof AdminUser && $user->can('system.horizon'));
        Gate::define('viewPulse', fn (mixed $user = null): bool => $user instanceof AdminUser && $user->can('system.pulse'));

        // Livewire's update endpoint is shared by both hosts; re-run the admin checks on every
        // Livewire request made from an admin page, not just on the first page load.
        Livewire::addPersistentMiddleware([
            EnsureAdminIpAllowed::class,
            EnsureAdminSessionIsValid::class,
            EnsureAdminIsActive::class,
            EnsureTwoFactorConfirmed::class,
        ]);

        // Staff passwords (and, from P1.1, member passwords): 12+ chars, mixed case, a number;
        // the breached-password check (an external API call) runs in production only.
        Password::defaults(fn (): Password => $this->app->isProduction()
            ? Password::min(12)->mixedCase()->numbers()->uncompromised()
            : Password::min(12)->mixedCase()->numbers());
    }
}
