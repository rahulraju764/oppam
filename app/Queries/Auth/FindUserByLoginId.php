<?php

declare(strict_types=1);

namespace App\Queries\Auth;

use App\Models\Profile;
use App\Models\User;
use App\ValueObjects\PhoneNumber;
use Illuminate\Support\Str;

/**
 * Finds the account behind the login box "Mobile / Email / Profile ID" (PRD §8.2 method 2):
 * an address with "@" is an email, OPM12370 is a profile code, anything else is a mobile
 * (a leading "+" for NRI numbers, otherwise India). Returns null for anything unknown — callers
 * must answer exactly as they would for a wrong password (R-M01-4).
 */
final class FindUserByLoginId
{
    public function handle(string $identifier): ?User
    {
        $identifier = trim($identifier);

        if (str_contains($identifier, '@')) {
            return User::query()->where('email', Str::lower($identifier))->first();
        }

        if (preg_match('/^OPM\d{4,9}$/i', $identifier) === 1) {
            $userId = Profile::query()->where('code', Str::upper($identifier))->value('user_id');

            return is_string($userId) ? User::query()->find($userId) : null;
        }

        $phone = PhoneNumber::tryParse($identifier);

        return $phone !== null ? User::query()->where('phone', $phone->e164())->first() : null;
    }

    /** A stable key for the per-identifier throttle, so "98765 43210" and "+919876543210" share it. */
    public function throttleKey(string $identifier): string
    {
        $identifier = trim($identifier);
        $phone = str_contains($identifier, '@') ? null : PhoneNumber::tryParse($identifier);

        return sha1($phone?->e164() ?? Str::lower($identifier));
    }
}
