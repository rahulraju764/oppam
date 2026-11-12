<?php

declare(strict_types=1);

namespace App\Data\Content;

use App\Enums\SettingKey;
use App\Support\Facades\Settings;
use Throwable;

/**
 * Everything a page's <head> needs (PRD §6.1, template head.php). A page sets what differs;
 * the rest falls back to site defaults. Robots is only honoured while the site is indexable
 * (A15 setting seo.indexable, the template's SITE_LIVE) — until then every page is noindex.
 */
final readonly class SeoData
{
    public const DEFAULT_TITLE = 'Oppam Matrimony | Trusted Kerala Matrimony';

    public const DEFAULT_DESCRIPTION = 'Oppam Matrimony helps Malayalis in Kerala find genuine life partners through verified profiles, secure matchmaking and trusted connections.';

    public const DEFAULT_KEYWORDS = 'Kerala Matrimony, Oppam Matrimony, Malayali Matrimony, Kerala Brides, Kerala Grooms';

    public function __construct(
        public string $title = self::DEFAULT_TITLE,
        public string $description = self::DEFAULT_DESCRIPTION,
        public string $keywords = self::DEFAULT_KEYWORDS,
        public ?string $ogTitle = null,
        public ?string $ogDescription = null,
        /** Only public marketing pages are indexable; member pages and errors never are. */
        public bool $indexable = true,
        /** Relative to public/. 1920×700 until a purpose-built 1200×630 share card exists. */
        public string $ogImage = 'images/banner/banner2.webp',
        public int $ogImageWidth = 1920,
        public int $ogImageHeight = 700,
    ) {}

    /** Pages behind auth: they name real people, so they are never indexed. */
    public static function private(string $title): self
    {
        return new self(title: $title, indexable: false);
    }

    /** The same page data, never indexed — member and broker layouts apply it to every $seo. */
    public function asPrivate(): self
    {
        return new self(
            title: $this->title,
            description: $this->description,
            keywords: $this->keywords,
            ogTitle: $this->ogTitle,
            ogDescription: $this->ogDescription,
            indexable: false,
            ogImage: $this->ogImage,
            ogImageWidth: $this->ogImageWidth,
            ogImageHeight: $this->ogImageHeight,
        );
    }

    public function robots(bool $siteIndexable): string
    {
        if (! $siteIndexable) {
            return 'noindex, nofollow, noarchive, nosnippet, noimageindex';
        }

        return $this->indexable ? 'index, follow' : 'noindex, nofollow';
    }

    /** The page's own title, or — for a page that set none — the A15 default title (P8.4). */
    public function pageTitle(): string
    {
        return $this->title === self::DEFAULT_TITLE ? self::siteDefault(SettingKey::SeoDefaultTitle, $this->title) : $this->title;
    }

    /** The page's own description, or the A15 default description (P8.4). */
    public function pageDescription(): string
    {
        return $this->description === self::DEFAULT_DESCRIPTION ? self::siteDefault(SettingKey::SeoDefaultDescription, $this->description) : $this->description;
    }

    public function ogTitle(): string
    {
        return $this->ogTitle ?? $this->pageTitle();
    }

    public function ogDescription(): string
    {
        return $this->ogDescription ?? $this->pageDescription();
    }

    private static function siteDefault(SettingKey $key, string $fallback): string
    {
        try {
            $value = trim(Settings::string($key));
        } catch (Throwable) {
            return $fallback;   // settings unreadable (e.g. the maintenance page with the database down)
        }

        return $value !== '' ? $value : $fallback;
    }
}
