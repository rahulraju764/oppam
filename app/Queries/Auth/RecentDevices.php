<?php

declare(strict_types=1);

namespace App\Queries\Auth;

use App\Data\Auth\DeviceData;
use App\Models\LoginEvent;
use App\Models\User;
use App\Support\Auth\UserAgentLabel;
use Carbon\CarbonImmutable;

/**
 * "Recent sign-ins" on the Account tab (M14): the member's own login_events of the last 90 days,
 * one line per device (the newest sign-in of each), at most 10. Sessions themselves can't be listed
 * (decisions 2026-09-29), so this is history, and "Sign out all other devices" is the action.
 * IPv4 shows its first three parts, IPv6 its first four groups.
 */
final class RecentDevices
{
    private const DAYS = 90;

    private const LIMIT = 10;

    /** @return list<DeviceData> */
    public function for(User $member, ?string $currentDeviceId): array
    {
        $current = is_string($currentDeviceId) && strlen($currentDeviceId) === 40 ? hash('sha256', $currentDeviceId) : null;

        $events = LoginEvent::query()
            ->where('user_id', $member->id)
            ->where('created_at', '>=', now()->subDays(self::DAYS))
            ->orderByDesc('created_at')
            ->limit(200)
            ->get(['device_hash', 'ip_address', 'user_agent', 'created_at']);

        $devices = [];
        foreach ($events->unique('device_hash')->take(self::LIMIT) as $event) {
            $devices[] = new DeviceData(
                label: UserAgentLabel::for($event->user_agent),
                ipMasked: self::maskIp($event->ip_address),
                lastSignedInAt: CarbonImmutable::parse($event->created_at),
                isThisDevice: $current !== null && hash_equals($current, $event->device_hash),
            );
        }

        return $devices;
    }

    public static function maskIp(?string $ip): string
    {
        if ($ip === null || $ip === '') {
            return __('unknown');
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $ip);

            return $parts[0].'.'.$parts[1].'.'.$parts[2].'.*';
        }

        return implode(':', array_slice(explode(':', $ip), 0, 4)).':…';
    }
}
