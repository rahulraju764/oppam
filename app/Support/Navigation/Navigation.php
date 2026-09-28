<?php

declare(strict_types=1);

namespace App\Support\Navigation;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;

/**
 * Resolves the site chrome's links: active state, the viewer's home, the shared Matches list
 * and the page-nav bar. The single rule it enforces: a link to a route that does not exist yet
 * is never rendered (template-notes.md — "don't link to a page you haven't built").
 */
final class Navigation
{
    public function __construct(
        private readonly Router $router,
        private readonly Request $request,
        private readonly AuthFactory $auth,
    ) {}

    /** @param  array<string, mixed>  $parameters */
    public function url(string $routeName, array $parameters = []): ?string
    {
        return $this->router->has($routeName) ? route($routeName, $parameters) : null;
    }

    /** @param  string|list<string>  $routeNames */
    public function isActive(string|array $routeNames): bool
    {
        return $this->request->routeIs(...(array) $routeNames);
    }

    public function isMember(): bool
    {
        return $this->auth->guard('web')->check();
    }

    /** Logo / "Home" target: the dashboard for a signed-in member, the landing page otherwise. */
    public function homeUrl(): string
    {
        return ($this->isMember() ? $this->url('member.dashboard') : null) ?? route('home');
    }

    public function homeLabel(): string
    {
        return $this->isMember() && $this->router->has('member.dashboard') ? __('Dashboard') : __('Home');
    }

    /**
     * Resolve a list of [route name, label, optional #fragment]; unbuilt routes are dropped.
     *
     * @param  list<array{0: string, 1: string, 2?: string}>  $items
     * @return list<NavLink>
     */
    public function links(array $items): array
    {
        $links = [];

        foreach ($items as $item) {
            $url = $this->url($item[0]);

            if ($url !== null) {
                $links[] = new NavLink($url.($item[2] ?? ''), __($item[1]), active: $this->isActive($item[0]));
            }
        }

        return $links;
    }

    /** @return list<NavLink> */
    public function matchesLinks(): array
    {
        $links = [];

        /** @var list<array{route: string, label: string, icon: string, description: string}> $items */
        $items = config('navigation.matches', []);

        foreach ($items as $item) {
            $url = $this->url($item['route']);

            if ($url !== null) {
                $links[] = new NavLink($url, __($item['label']), $item['icon'], __($item['description']), $this->isActive($item['route']));
            }
        }

        return $links;
    }

    public function inMatchesSection(): bool
    {
        return $this->isActive(config('navigation.matches_section', []));
    }

    public function inProfileSection(): bool
    {
        return $this->isActive(config('navigation.profile_section', []));
    }

    /**
     * The page-nav bar for the current route, or null when the route is not in the map.
     * $overrides lets a page replace title/prev/next at runtime ([url, label] or null to drop).
     *
     * @param  array{title?: string, prev?: array{0: string, 1: string}|null, next?: array{0: string, 1: string}|null}  $overrides
     */
    public function pageNav(array $overrides = []): ?PageNav
    {
        $routeName = $this->request->route()?->getName();

        // Not config("pagenav.{$routeName}"): route names contain dots, which config() would
        // treat as nesting ('member.profiles' → ['member']['profiles']) and never find.
        /** @var array<string, array{title: string, back?: array{0: string, 1: string}, prev?: array{0: string, 1: string}, next?: array{0: string, 1: string}}> $map */
        $map = config('pagenav', []);
        $entry = $routeName !== null ? ($map[$routeName] ?? null) : null;

        if ($entry === null) {
            return null;
        }

        $back = isset($entry['back']) ? $this->mapLink($entry['back']) : null;

        return new PageNav(
            title: $overrides['title'] ?? __($entry['title']),
            back: $back ?? new NavLink($this->homeUrl(), $this->homeLabel()),
            prev: array_key_exists('prev', $overrides) ? $this->runtimeLink($overrides['prev']) : $this->mapLink($entry['prev'] ?? null),
            next: array_key_exists('next', $overrides) ? $this->runtimeLink($overrides['next']) : $this->mapLink($entry['next'] ?? null),
        );
    }

    /** @param  array{0: string, 1: string}|null  $link  [route name, label] */
    private function mapLink(?array $link): ?NavLink
    {
        if ($link === null) {
            return null;
        }

        $url = $this->url($link[0]);

        return $url !== null ? new NavLink($url, __($link[1])) : null;
    }

    /** @param  array{0: string, 1: string}|null  $link  [url, label] — already resolved by the page */
    private function runtimeLink(?array $link): ?NavLink
    {
        return $link !== null ? new NavLink($link[0], $link[1]) : null;
    }
}
