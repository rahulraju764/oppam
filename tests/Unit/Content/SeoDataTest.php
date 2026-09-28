<?php

declare(strict_types=1);

use App\Data\Content\SeoData;

it('forces noindex on every page while the site is not indexable (template SITE_LIVE)', function (SeoData $seo): void {
    expect($seo->robots(siteIndexable: false))->toBe('noindex, nofollow, noarchive, nosnippet, noimageindex');
})->with([
    'public page' => [new SeoData],
    'member page' => [SeoData::private('My Profile')],
]);

it('indexes public pages and never member pages once the site is indexable', function (): void {
    expect((new SeoData)->robots(siteIndexable: true))->toBe('index, follow')
        ->and(SeoData::private('Dashboard')->robots(siteIndexable: true))->toBe('noindex, nofollow');
});

it('falls back to the page title and description for Open Graph', function (): void {
    $seo = new SeoData(title: 'About Us', description: 'Who we are');

    expect($seo->ogTitle())->toBe('About Us')
        ->and($seo->ogDescription())->toBe('Who we are')
        ->and((new SeoData(title: 'A', ogTitle: 'B'))->ogTitle())->toBe('B');
});
