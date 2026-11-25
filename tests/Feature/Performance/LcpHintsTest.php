<?php

declare(strict_types=1);

use App\Models\Banner;
use Database\Seeders\ContentSeeder;
use Database\Seeders\PlansSeeder;

/*
| P9.3c (PRD §14: LCP < 2.5 s on 4G) — the home page's LCP element is the hero's first banner: it is preloaded
| with high priority from the <head>, and the site chrome (logos, avatar, preloader) no longer claims
| fetchpriority="high", which made those images compete with it on every page.
*/

beforeEach(function (): void {
    $this->seed(PlansSeeder::class);
    $this->seed(ContentSeeder::class);
});

it('LCP: the home page preloads its first hero banner with high priority', function (): void {
    $first = app(App\Queries\Content\PublicContent::class)->banners()[0] ?? null;   // the list the hero itself loops over
    expect($first)->not->toBeNull();

    $html = $this->get('/')->assertOk()->getContent();
    $href = e($first->imageUrl);

    expect($html)->toContain('<link rel="preload" as="image" href="'.$href.'" fetchpriority="high">')
        ->and(substr_count($html, 'rel="preload" as="image"'))->toBe(1)
        ->and($html)->toContain('src="'.$href.'"');   // the same image the hero's first slide shows
});

it('LCP: pages without a declared LCP image get no image preload', function (): void {
    expect($this->get('/plans')->assertOk()->getContent())->not->toContain('rel="preload" as="image"');
});

it('LCP: the site chrome (logos, avatar, preloader) never claims fetchpriority="high"', function (): void {
    $this->actingAs(memberWithPhone());

    foreach (['/', '/plans'] as $path) {
        $html = (string) $this->get($path)->getContent();
        preg_match_all('#<img[^>]*fetchpriority="high"[^>]*>#', $html, $high);
        $chrome = array_filter($high[0], fn (string $img): bool => str_contains($img, 'logo') || str_contains($img, 'preloader') || str_contains($img, 'width="600" height="600"'));

        expect($chrome)->toBe([], "chrome image claims high priority on $path");
    }
});
