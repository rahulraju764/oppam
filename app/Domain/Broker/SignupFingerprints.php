<?php

declare(strict_types=1);

namespace App\Domain\Broker;

use Illuminate\Contracts\Cookie\QueueingFactory as CookieJar;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * How a referred member signed up (§11A B.12 "many referrals from one IP/device", "broker referring
 * themselves"; owner 2026-10-29: capture it now). Privacy (B.18, DPDP): the IP and its network
 * (/24 for IPv4, /48 for IPv6) are kept only as HMAC-SHA256 with the app key — never the address;
 * the device is sha256 of the long-lived `oppam_device` cookie, the same value login_events keeps,
 * so a referral made on a bureau login's own device can be matched. A visitor without the cookie
 * gets one now (as SignInMember does).
 */
final class SignupFingerprints
{
    public function __construct(
        private readonly Request $request,
        private readonly CookieJar $cookies,
    ) {}

    /** @return array{signup_ip_hash: string|null, signup_net_hash: string|null, signup_device_hash: string} */
    public function current(): array
    {
        $ip = $this->request->ip();

        return [
            'signup_ip_hash' => $ip !== null ? self::ipHash($ip) : null,
            'signup_net_hash' => $ip !== null ? self::netHash($ip) : null,
            'signup_device_hash' => hash('sha256', $this->deviceId()),
        ];
    }

    public static function ipHash(string $ip): string
    {
        return hash_hmac('sha256', 'ip|'.$ip, self::key());
    }

    /** The /24 (IPv4) or /48 (IPv6) network, keyed; null for something that isn't an IP. */
    public static function netHash(string $ip): ?string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        $network = strlen($packed) === 4 ? substr($packed, 0, 3) : substr($packed, 0, 6);

        return hash_hmac('sha256', 'net|'.bin2hex($network), self::key());
    }

    private function deviceId(): string
    {
        $name = (string) config('oppam.auth.device_cookie');
        $id = $this->request->cookie($name);

        if (! is_string($id) || strlen($id) !== 40) {
            $id = Str::random(40);
            $this->cookies->queue($this->cookies->forever($name, $id, httpOnly: true, sameSite: 'lax'));
        }

        return $id;
    }

    private static function key(): string
    {
        return (string) config('app.key');
    }
}
