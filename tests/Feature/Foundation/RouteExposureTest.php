<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;

/*
| Package auto-discovery once exposed ~50 unplanned routes (Fortify account pages,
| Passkeys, Dusk login-as-anyone, public Horizon/Pulse, generic private-file serving).
| These tests keep them closed. See docs/decisions.md (2026-09-27).
*/

function routesWhoseActionContains(string $needle): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RouteDefinition $route): bool => str_contains($route->getActionName(), $needle))
        ->map(fn (RouteDefinition $route): string => $route->uri())
        ->values()
        ->all();
}

it('registers no Fortify or Passkeys routes (member auth is our own OTP flow)', function (): void {
    expect(routesWhoseActionContains('Laravel\Fortify'))->toBe([])
        ->and(routesWhoseActionContains('Laravel\Passkeys'))->toBe([]);
});

it('does not expose the generic private-file serving route', function (): void {
    expect(Route::has('storage.local'))->toBeFalse()
        ->and(Route::has('storage.local.upload'))->toBeFalse();
});

it('keeps the Horizon and Pulse dashboards on the admin domain only', function (string $routeName): void {
    expect(Route::getRoutes()->getByName($routeName)?->getDomain())->toBe(config('oppam.admin_domain'));
})->with(['horizon.index', 'pulse']);

it('never serves the public home page on the admin domain', function (): void {
    // "/" on the admin domain is the admin dashboard: a guest is sent to the ADMIN login.
    $this->get('http://'.config('oppam.admin_domain').'/')
        ->assertRedirect(route('admin.login'))
        ->assertDontSee('matrimony-slides');
});

it('only loads the Dusk login helper routes in local and testing', function (): void {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

    expect($composer['extra']['laravel']['dont-discover'])->toContain('laravel/dusk')
        ->and(app()->environment())->toBe('testing');
});

it('does not answer public routes on an unknown host', function (): void {
    $this->get('http://evil.example/')->assertNotFound();
});

it('still answers the uptime probe on any host', function (): void {
    $this->get('http://10.0.0.5/up')->assertOk();
});
