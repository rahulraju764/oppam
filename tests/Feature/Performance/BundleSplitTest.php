<?php

declare(strict_types=1);

/*
| P9.3c (PRD §14 LCP) — Swiper is ~150 KB of JS that only the home page, the dashboard strips and the ad rail
| use: it is a separate chunk loaded on demand (resources/js/template/carousels.js), never part of the entry
| bundle every member / public / broker page downloads. Reads the built manifest (CI runs `npm run build`
| before the tests; locally after `npm run build`).
*/

it('perf: Swiper is not in the app entry bundle — it is its own chunk, loaded only on pages with a carousel', function (): void {
    $manifestPath = public_path('build/manifest.json');
    expect(is_file($manifestPath))->toBeTrue('No production build — run `npm run build` (CI does before the tests).');

    /** @var array<string, array{file: string, dynamicImports?: list<string>}> $manifest */
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $entry = $manifest['resources/js/app.js'] ?? null;
    expect($entry)->not->toBeNull();

    $entryJs = (string) file_get_contents(public_path('build/'.$entry['file']));
    $swiperChunks = array_filter(array_keys($manifest), fn (string $key): bool => str_contains($key, 'swiper'));

    expect($entryJs)->not->toContain('swiper-slide-active')   // a string only Swiper's own code carries
        ->and($swiperChunks)->not->toBeEmpty('Swiper should be emitted as its own chunk')
        ->and(strlen($entryJs))->toBeLessThan(150 * 1024);   // was ~238 KB with Swiper inside

    // Every chunk the entry imports STATICALLY loads on every page too (e.g. the shared preload helper) —
    // Swiper must not be hoisted into one of them (review P9.3c).
    foreach ($entry['imports'] ?? [] as $import) {
        $file = $manifest[$import]['file'] ?? null;
        expect($file)->not->toBeNull()
            ->and((string) file_get_contents(public_path('build/'.$file)))->not->toContain('swiper-slide-active', "Swiper is in the statically imported chunk $file");
    }
});
