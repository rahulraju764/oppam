<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\SmsGateway;
use App\Domain\Billing\UsagePeriodResolver;
use App\Http\Middleware\EnsureAdminIpAllowed;
use App\Http\Middleware\EnsureAdminIsActive;
use App\Http\Middleware\EnsureAdminSessionIsValid;
use App\Http\Middleware\EnsurePhoneIsVerified;
use App\Http\Middleware\EnsureProfileOnboarded;
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
use App\Services\Entitlements\EntitlementService;
use App\Services\Settings\FeatureFlags;
use App\Services\Settings\SettingsRepository;
use App\Services\Sms\LogSmsGateway;
use App\Services\Sms\Msg91Gateway;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Dusk\DuskServiceProvider;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Passkeys;
use Livewire\Livewire;
use RuntimeException;
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

        // One instance per request: they memoise settings / flags / the member's current plan.
        $this->app->scoped(SettingsRepository::class);
        $this->app->scoped(FeatureFlags::class);
        $this->app->scoped(EntitlementService::class);
        $this->app->singleton(UsagePeriodResolver::class, fn (): UsagePeriodResolver => new UsagePeriodResolver(config('oppam.display_timezone')));

        $this->app->singleton(SmsGateway::class, function (): SmsGateway {
            $driver = (string) config('oppam.sms.driver');

            // The log gateway writes codes to a file: never in production.
            if ($driver === 'log' && $this->app->isProduction()) {
                throw new RuntimeException('SMS_DRIVER=log is not allowed in production.');
            }

            return match ($driver) {
                'msg91' => new Msg91Gateway(
                    $this->app->make(HttpFactory::class),
                    (string) config('services.msg91.auth_key'),
                    (string) config('services.msg91.otp_template_id'),
                    (string) config('services.msg91.account_exists_template_id'),
                ),
                'log' => $this->app->make(LogSmsGateway::class),
                default => throw new RuntimeException("Unknown SMS_DRIVER [{$driver}]."),
            };
        });
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

        // "Stay logged in" lasts 30 days for members (PRD §8.1), not Laravel's 400-day default.
        Auth::resolved(function (AuthFactory $auth): void {
            $guard = $auth->guard('web');

            if ($guard instanceof SessionGuard) {
                $guard->setRememberDuration((int) config('oppam.auth.remember_days') * 24 * 60);
            }
        });

        // Member pages keep their access checks on every Livewire update, not just the first load.
        Livewire::addPersistentMiddleware([
            EnsurePhoneIsVerified::class,
            EnsureProfileOnboarded::class,
        ]);
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

        // Staff passwords: 12+ chars, mixed case, a number; the breached-password check (an
        // external API call) runs in production only. Members use App\Support\Auth\MemberPassword.
        Password::defaults(fn (): Password => $this->app->isProduction()
            ? Password::min(12)->mixedCase()->numbers()->uncompromised()
            : Password::min(12)->mixedCase()->numbers());
    }
}
