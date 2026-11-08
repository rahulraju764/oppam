<?php

declare(strict_types=1);

namespace App\Support\Auth;

/**
 * A short, human label for a browser user agent ("Chrome on Android"), for the Account tab's
 * recent sign-ins (M14). Deliberately rough: a handful of patterns, no package; anything unknown
 * is "Unknown browser" / "unknown device". Never used for security decisions.
 */
final class UserAgentLabel
{
    public static function for(?string $userAgent): string
    {
        $ua = (string) $userAgent;

        return __(':browser on :os', ['browser' => self::browser($ua), 'os' => self::os($ua)]);
    }

    private static function browser(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => __('Unknown browser'),
        };
    }

    private static function os(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iPhone / iPad',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'Mac',
            str_contains($ua, 'CrOS') => 'Chromebook',
            str_contains($ua, 'Linux') => 'Linux',
            default => __('unknown device'),
        };
    }
}
