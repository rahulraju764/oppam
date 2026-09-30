<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * One-time code rules (R-M01-2): 6 random digits; stored only as an HMAC bound to its challenge,
 * so a leaked hash can't be replayed against another challenge and a DB dump reveals no codes.
 */
final class OtpCode
{
    public const LENGTH = 6;

    public static function generate(): string
    {
        return str_pad((string) random_int(0, 10 ** self::LENGTH - 1), self::LENGTH, '0', STR_PAD_LEFT);
    }

    public static function hash(string $challengeId, string $code, string $key): string
    {
        return hash_hmac('sha256', $challengeId.'|'.$code, $key);
    }

    public static function matches(string $challengeId, string $code, string $hash, string $key): bool
    {
        return hash_equals($hash, self::hash($challengeId, $code, $key));
    }

    /** Only well-formed codes are ever compared (anything else counts as a wrong attempt). */
    public static function isWellFormed(string $code): bool
    {
        return preg_match('/^\d{'.self::LENGTH.'}$/', $code) === 1;
    }
}
