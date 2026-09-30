<?php

declare(strict_types=1);

namespace App\Data\Content;

use App\Enums\SettingKey;
use App\Services\Settings\SettingsRepository;

/**
 * The contact details shown in the footer and on /contact — ONE source for both (P0.3 decision).
 * Editable values come from A15 settings; the map and social links are deployment config
 * (they are URLs to third-party services, set per environment).
 */
final readonly class SiteContactData
{
    /**
     * @param  list<string>  $emails
     * @param  array<string, string>  $phones  dial string => display
     * @param  list<string>  $addressLines
     * @param  array<string, string>  $social  network => URL (only the configured ones)
     */
    public function __construct(
        public array $emails,
        public array $phones,
        public array $addressLines,
        public string $hours,
        public ?string $mapUrl,
        public ?string $mapEmbedUrl,
        public array $social,
    ) {}

    public static function current(): self
    {
        $settings = app(SettingsRepository::class);

        /** @var array<string, string|null> $social */
        $social = config('oppam.site.social', []);

        return new self(
            emails: array_values(array_filter([$settings->string(SettingKey::SiteSupportEmail), $settings->string(SettingKey::SiteInfoEmail)])),
            phones: [$settings->string(SettingKey::SitePhoneDial) => $settings->string(SettingKey::SitePhoneDisplay)],
            addressLines: array_values(array_filter(array_map('trim', explode("\n", $settings->string(SettingKey::SiteAddress))))),
            hours: $settings->string(SettingKey::SiteHours),
            mapUrl: config('oppam.site.map_url'),
            mapEmbedUrl: config('oppam.site.map_embed_url'),
            social: array_filter($social),
        );
    }
}
