<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Enums\SettingKey;
use App\Models\Setting;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The site's name and logo (A15 "Site settings", P8.4 — owner 2026-11-12: editable). The name is
 * applied to config('oppam.site.name') at the start of each web request (ApplySiteIdentity
 * middleware) and queued job (AppServiceProvider: Queue::before), so every
 * page title, email and PDF that already reads that config follows the setting. The logo is an
 * upload on the public disk (UploadSiteLogo); without one, the bundled logo. If the settings
 * can't be read (database down: maintenance page, first install) the deployment values stay.
 */
final class SiteIdentity
{
    public const BUNDLED_LOGO = 'images/logo/oppam-logo.webp';

    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * One primary-key read, not the settings cache: it runs at the start of every web request and
     * queued job (ApplySiteIdentity, Queue::before), and must not warm the cache of the request.
     */
    public function applyName(): void
    {
        try {
            $value = Setting::query()->find(SettingKey::SiteName->value)?->value;   // json-cast
        } catch (Throwable) {
            return;   // no database / no table yet: the deployment's name stays
        }

        $name = is_string($value) ? trim($value) : '';
        if ($name !== '') {
            config(['oppam.site.name' => $name]);
        }
    }

    /** The maintenance page's text: the admin's message, else '' (the page's built-in text); '' when settings can't be read. */
    public function maintenanceMessage(): string
    {
        try {
            return trim($this->settings->string(SettingKey::SiteMaintenanceMessage));
        } catch (Throwable) {
            return '';
        }
    }

    public function logoUrl(): string
    {
        try {
            $path = $this->settings->string(SettingKey::SiteLogoPath);
        } catch (Throwable) {
            $path = '';
        }

        return $path !== '' ? Storage::disk('public')->url($path) : asset(self::BUNDLED_LOGO);
    }
}
