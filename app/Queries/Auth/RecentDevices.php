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
 * one line per device (the newest sign-in of each, picked in SQL), at most 10. Sessions themselves
 * can't be listed (decisions 2026-09-29), so this is history, and "Sign out all other devices" is
 * the action. IPv4 shows its first three parts, IPv6 its first four groups (expanded first).
 */
final class RecentDevices
{
    private const DAYS = 90;

    private const LIMIT = 10;

    /** @return list<DeviceData> */
    public function for(User $member, ?string $currentDeviceId): array
    {
        $current = is_string($currentDeviceId) && strlen($currentDeviceId) === 40 ? hash('sha256', $currentDeviceId) : null;

        // ULIDs sort by time: MAX(id) per device is that device's newest sign-in.
        $newest = LoginEvent::query()
            ->selectRaw('MAX(id)')
            ->where('user_id', $member->id)
            ->where('created_at', '>=', now()->subDays(self::DAYS))
            ->groupBy('device_hash');

        $events = LoginEvent::query()
            ->whereIn('id', $newest)
            ->orderByDesc('created_at')
            ->limit(self::LIMIT)
            ->get(['device_hash', 'ip_address', 'user_agent', 'created_at']);

        $devices = [];
        foreach ($events as $event) {
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

        $packed = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? inet_pton($ip) : false;
        if ($packed === false) {
            return __('unknown');
        }
        $groups = array_map(static fn (string $g): string => ltrim($g, '0') === '' ? '0' : ltrim($g, '0'), str_split(bin2hex($packed), 4));

        return implode(':', array_slice($groups, 0, 4)).':…';
    }
}
