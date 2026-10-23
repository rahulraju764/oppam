<?php

declare(strict_types=1);

namespace App\Domain\Broker;

use App\Enums\BureauRole;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * "Owner and Manager must pass an OTP challenge on each new device" (PRD §8.1, B.18). A device is
 * the random cookie SignInMember sets (`oppam.auth.device_cookie`), stored only as its SHA-256.
 * It becomes trusted once an OTP is proven on it — a sign-in with an OTP (or registration /
 * application) counts; a password sign-in on an unknown device must pass Broker\Auth\VerifyDevice.
 */
final class TrustedDevices
{
    /** Roles that need the challenge. */
    public const ROLES = [BureauRole::Owner, BureauRole::Manager];

    public function hash(Request $request): ?string
    {
        $device = $request->cookie((string) config('oppam.auth.device_cookie'));

        return is_string($device) && strlen($device) === 40 ? hash('sha256', $device) : null;
    }

    public function isTrusted(User $user, Request $request): bool
    {
        $hash = $this->hash($request);

        return $hash !== null && TrustedDevice::query()->where('user_id', $user->id)->where('device_hash', $hash)->exists();
    }

    /** Remember the device by its hash (idempotent). */
    public function trust(User $user, string $deviceHash): void
    {
        TrustedDevice::query()->upsert(
            [['id' => (new TrustedDevice)->newUniqueId(), 'user_id' => $user->id, 'device_hash' => $deviceHash,
                'verified_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]],
            ['user_id', 'device_hash'],
            ['last_seen_at', 'updated_at'],
        );
    }
}
