<?php

declare(strict_types=1);

use App\Queries\Auth\RecentDevices;
use App\Support\Auth\UserAgentLabel;

/* P8.2b — the Account tab's recent sign-ins: a rough browser / OS label and a masked IP. */

it('labels common user agents', function (?string $ua, string $label): void {
    expect(UserAgentLabel::for($ua))->toBe($label);
})->with([
    'chrome android' => ['Mozilla/5.0 (Linux; Android 14; SM-A145F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36', 'Chrome on Android'],
    'safari iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1', 'Safari on iPhone / iPad'],
    'edge windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36 Edg/124.0', 'Edge on Windows'],
    'firefox mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14.4; rv:125.0) Gecko/20100101 Firefox/125.0', 'Firefox on Mac'],
    'samsung' => ['Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/24.0 Chrome/117.0 Mobile Safari/537.36', 'Samsung Internet on Android'],
    'nothing' => [null, 'Unknown browser on unknown device'],
]);

it('masks the IP: three parts of IPv4, four groups of IPv6', function (?string $ip, string $masked): void {
    expect(RecentDevices::maskIp($ip))->toBe($masked);
})->with([
    ['203.0.113.45', '203.0.113.*'],
    ['2001:db8:85a3:8d3:1319:8a2e:370:7348', '2001:db8:85a3:8d3:…'],
    ['2001:db8::1', '2001:db8:0:0:…'],   // compressed: expanded before masking
    [null, 'unknown'],
]);
