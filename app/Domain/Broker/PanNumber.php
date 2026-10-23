<?php

declare(strict_types=1);

namespace App\Domain\Broker;

/**
 * A PAN (Indian tax id) as a bureau gives it (§11A KYC). Normalised to upper case without spaces.
 * Outside the encrypted KYC row only its last 4 and a keyed hash are kept — the hash makes "one
 * PAN = one bureau" checkable without storing the number in the clear.
 */
final class PanNumber
{
    public const PATTERN = '/^[A-Z]{5}[0-9]{4}[A-Z]$/';

    public static function normalise(string $raw): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $raw));
    }

    public static function isValid(string $pan): bool
    {
        return preg_match(self::PATTERN, $pan) === 1;
    }

    public static function last4(string $pan): string
    {
        return substr($pan, -4);
    }

    public static function hash(string $pan): string
    {
        return hash_hmac('sha256', self::normalise($pan), 'broker-pan|'.(string) config('app.key'));
    }
}
