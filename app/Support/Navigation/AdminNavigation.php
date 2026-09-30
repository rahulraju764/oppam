<?php

declare(strict_types=1);

namespace App\Support\Navigation;

use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;

/**
 * The admin sidebar (config/admin-navigation.php) for one admin: an item appears only when its
 * route exists and the admin holds its permission; empty sections are dropped. Presentation
 * only — every admin page and action authorizes again on the server.
 */
final class AdminNavigation
{
    public function __construct(
        private readonly Router $router,
        private readonly Request $request,
    ) {}

    /** @return array<string, list<NavLink>> section => links */
    public function sections(?AdminUser $admin): array
    {
        if ($admin === null) {
            return [];
        }

        $sections = [];

        /** @var array<string, list<array{0: string, 1: string, 2: string, 3: string|null}>> $config */
        $config = config('admin-navigation', []);

        foreach ($config as $section => $items) {
            foreach ($items as [$routeName, $label, $icon, $permission]) {
                if (! $this->router->has($routeName) || ($permission !== null && ! $admin->can($permission))) {
                    continue;
                }

                $sections[$section][] = new NavLink(route($routeName), __($label), $icon, active: $this->request->routeIs($routeName));
            }
        }

        return $sections;
    }
}
