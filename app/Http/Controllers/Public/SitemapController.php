<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enums\SettingKey;
use App\Http\Controllers\Controller;
use App\Models\CommunityPage;
use App\Support\Facades\Settings;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * /sitemap.xml (M12 SEO, PRD §14): the public pages plus every published community landing page.
 * Members' pages are never listed. While the site isn't indexable (A15 `seo.indexable`) the sitemap
 * is empty. Cached for an hour; a published community page appears within that hour.
 */
final class SitemapController extends Controller
{
    public const CACHE_KEY = 'sitemap:xml';

    /** Public routes in the sitemap, with their change frequency. */
    private const PAGES = [
        'home' => 'daily',
        'about' => 'monthly',
        'success-stories' => 'weekly',
        'branches' => 'monthly',
        'plans' => 'weekly',
        'contact' => 'yearly',
        'safety' => 'monthly',
        'terms' => 'yearly',
        'privacy' => 'yearly',
        'grievance' => 'yearly',
    ];

    public function __invoke(): Response
    {
        $xml = Settings::bool(SettingKey::SeoIndexable)
            ? Cache::remember(self::CACHE_KEY, 3600, fn (): string => $this->build())
            : $this->urlset([]);

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    private function build(): string
    {
        $entries = [];
        foreach (self::PAGES as $route => $frequency) {
            $entries[] = ['loc' => route($route), 'changefreq' => $frequency, 'lastmod' => null];
        }

        CommunityPage::query()->live()->orderBy('slug')->get(['slug', 'updated_at'])
            ->each(function (CommunityPage $page) use (&$entries): void {
                $entries[] = ['loc' => route('community', ['community' => $page->slug]), 'changefreq' => 'weekly', 'lastmod' => $page->updated_at?->toDateString()];
            });

        return $this->urlset($entries);
    }

    /** @param  list<array{loc: string, changefreq: string, lastmod: string|null}>  $entries */
    private function urlset(array $entries): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($entries as $entry) {
            $xml .= '  <url><loc>'.htmlspecialchars($entry['loc'], ENT_XML1).'</loc>'
                .($entry['lastmod'] !== null ? '<lastmod>'.$entry['lastmod'].'</lastmod>' : '')
                .'<changefreq>'.$entry['changefreq'].'</changefreq></url>'."\n";
        }

        return $xml.'</urlset>'."\n";
    }
}
