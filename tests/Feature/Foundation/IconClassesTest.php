<?php

declare(strict_types=1);

use App\Enums\MatchTab;
use App\Enums\NotificationEvent;
use App\Enums\NotificationGroup;
use App\Enums\ReportKey;

/*
| P9.3a — Font Awesome 6 (docs/fa4-to-fa6-map.md). Every icon in the code is a FULL FA6 class,
| "fa-{solid|regular|brands} fa-{name}", with a name that exists in the installed package for that
| style (free). No FA 4.7 name, no bare "fa" class, no icon without its style. Reads the package's own
| metadata (node_modules — CI runs `npm ci` before the tests).
*/

const ICON_SCAN_DIRS = ['resources/views', 'resources/js', 'app', 'config', 'routes', 'database', 'lang'];

/** FA modifiers / utilities that are not icons. */
const ICON_MODIFIERS = ['spin', 'pulse', 'beat', 'fade', 'bounce', 'shake', 'flip', 'lg', 'xs', 'sm', 'xl', '2xs', '2xl', '1x', '2x', '3x', '4x', '5x',
    'fw', 'border', 'pull-left', 'pull-right', 'stack', 'stack-1x', 'stack-2x', 'inverse', 'rotate-90', 'rotate-180', 'rotate-270',
    'flip-horizontal', 'flip-vertical', 'flip-both', 'li', 'ul', 'sr-only'];

const ICON_STYLES = ['solid', 'regular', 'brands'];

/** @return array<string, list<string>> FA6 icon name => free styles (solid / regular / brands) */
function fa6FreeIcons(): array
{
    static $icons = null;
    if ($icons !== null) {
        return $icons;
    }

    $file = base_path('node_modules/@fortawesome/fontawesome-free/metadata/icon-families.json');
    expect(is_file($file))->toBeTrue('Font Awesome 6 metadata not found — run `npm install` (CI runs `npm ci`).');

    $icons = [];
    foreach (json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) as $name => $icon) {
        $styles = [];
        foreach ($icon['familyStylesByLicense']['free'] ?? [] as $style) {
            if ($style['family'] === 'classic') {
                $styles[] = $style['style'];
            }
        }
        if ($styles !== []) {
            $icons[$name] = $styles;
        }
    }

    return $icons;
}

/** @return iterable<string, string> relative path => contents, for every PHP / Blade / JS file in the scanned folders */
function iconSourceFiles(): iterable
{
    foreach (ICON_SCAN_DIRS as $dir) {
        if (! is_dir(base_path($dir))) {
            continue;
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (preg_match('/\.(php|js)$/', $file->getFilename()) === 1) {
                yield str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1)) => (string) file_get_contents($file->getPathname());
            }
        }
    }
}

it('FA6: every icon is "fa-{style} fa-{name}" with a name that exists, free, in that style', function (): void {
    $icons = fa6FreeIcons();
    $problems = [];

    foreach (iconSourceFiles() as $path => $source) {
        // Each style class must be followed by an icon that exists in that style.
        preg_match_all('/(?<![A-Za-z0-9_-])fa-(solid|regular|brands)\s+fa-([a-z0-9-]+)/', $source, $pairs, PREG_SET_ORDER);
        foreach ($pairs as [$__, $style, $name]) {
            if (! in_array($style, $icons[$name] ?? [], true)) {
                $problems[] = "$path: fa-$style fa-$name — not a free FA6 $style icon";
            }
        }

        // Every other fa- token is a style, a modifier, or an icon that directly follows its style.
        preg_match_all('/(?<![A-Za-z0-9_-])fa-([a-z0-9-]+)(?![A-Za-z0-9_-])/', $source, $tokens, PREG_OFFSET_CAPTURE);
        foreach ($tokens[1] as [$token, $offset]) {
            if (in_array($token, ICON_STYLES, true) || in_array($token, ICON_MODIFIERS, true)) {
                continue;
            }
            $before = substr($source, max(0, $offset - 20), min(20, $offset));
            if (preg_match('/fa-(solid|regular|brands)\s+fa-$/', $before) !== 1) {
                $problems[] = "$path: fa-$token — an icon without its style (or a FA 4.7 name)";
            }
        }
    }

    expect($problems)->toBe([]);
});

it('FA6: no bare "fa" class is left (FA 4.7 markup)', function (): void {
    $problems = [];

    foreach (iconSourceFiles() as $path => $source) {
        // a bare "fa" anywhere in a class list, a FA4 array key, a className string, or an icon name built at runtime
        // ('fa-'.$x / "fa-" + x / `fa-${x}`), which no static check could see
        if (preg_match_all('/class="(?:[^"]*\s)?fa[\s"]|["\']fa\s+(?:fa-|\{\{)|\[\'fa\',|className\s*=\s*[\'"]fa\s|[\'"]fa-[\'"]\s*[.+]|`fa-\$\{|fa-\{|class=\'(?:[^\']*\s)?fa[\s\']|\'fa\'\s*=>/', $source, $m) > 0) {
            $problems[] = "$path: ".implode(' | ', array_unique($m[0]));
        }
    }

    expect($problems)->toBe([]);
});

it('FA6: icons that live in enums and navigation config are full FA6 classes', function (): void {
    $icons = fa6FreeIcons();
    $values = [
        ...array_map(fn (MatchTab $t): string => $t->icon(), MatchTab::cases()),
        ...array_map(fn (NotificationGroup $g): string => $g->icon(), NotificationGroup::cases()),
        ...array_map(fn (NotificationEvent $e): string => $e->icon(), NotificationEvent::cases()),
        ...array_map(fn (ReportKey $r): string => $r->icon(), ReportKey::cases()),
    ];
    // Admin sidebar: positional [route, label, icon, permission]; member nav: 'icon' keys.
    foreach ((array) config('admin-navigation') as $links) {
        foreach ((array) $links as $link) {
            $values[] = (string) $link[2];
        }
    }
    $nav = (array) config('navigation');
    array_walk_recursive($nav, function ($value, $key) use (&$values): void {
        if ($key === 'icon' && is_string($value)) {
            $values[] = $value;
        }
    });

    expect($values)->not->toBeEmpty();
    foreach ($values as $value) {
        expect($value)->toMatch('/^fa-(solid|regular|brands) fa-[a-z0-9-]+$/');
        [$style, $name] = [substr(explode(' ', $value)[0], 3), substr(explode(' ', $value)[1], 3)];
        expect($icons[$name] ?? [])->toContain($style);
    }
});

it('FA6: CSS selectors on icons name FA6 icons (no FA 4.7 selector goes stale)', function (): void {
    $icons = fa6FreeIcons();
    $problems = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('css'), FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'css') {
            continue;
        }
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($file->getPathname()));   // comments may mention old names
        preg_match_all('/\.fa-([a-z0-9-]+)/', $css, $m);
        foreach (array_unique($m[1]) as $token) {
            if (! in_array($token, ICON_STYLES, true) && ! in_array($token, ICON_MODIFIERS, true) && ! isset($icons[$token])) {
                $problems[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(base_path()) + 1)).": .fa-$token";
            }
        }
        if (preg_match('/font-family:\s*[\'"]?FontAwesome|font:[^;]*FontAwesome/i', $css) === 1) {
            $problems[] = $file->getFilename().': FontAwesome (4.7) font family';
        }
    }

    expect($problems)->toBe([]);
});
