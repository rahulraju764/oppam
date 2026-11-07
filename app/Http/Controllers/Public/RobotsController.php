<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enums\SettingKey;
use App\Http\Controllers\Controller;
use App\Support\Facades\Settings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * /robots.txt (M12 SEO, PRD §15: APP_INDEXABLE replaces the template's SITE_LIVE). Indexable: crawl
 * everything public except member and bureau areas, and here is the sitemap. Not indexable (before
 * launch) and always on the admin host: crawl nothing. The pages say noindex too (layouts/partials/head).
 */
final class RobotsController extends Controller
{
    /** Signed-in areas: never useful to a crawler. */
    private const PRIVATE_PATHS = ['/dashboard', '/me', '/profile/', '/profiles', '/search', '/matches', '/messages', '/settings', '/billing', '/checkout', '/broker/', '/content-preview/', '/legal/accept'];

    public function __invoke(Request $request): Response
    {
        $lines = ['User-agent: *'];

        if (Settings::bool(SettingKey::SeoIndexable) && $request->getHost() !== config('oppam.admin_domain')) {
            foreach (self::PRIVATE_PATHS as $path) {
                $lines[] = 'Disallow: '.$path;
            }
            $lines[] = '';
            $lines[] = 'Sitemap: '.route('sitemap');
        } else {
            $lines[] = 'Disallow: /';
        }

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
