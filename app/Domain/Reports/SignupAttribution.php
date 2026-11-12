<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Enums\SignupSource;

/**
 * A09 signup attribution (P9.1a): the first-touch utm values, sanitised, and the source a new
 * member is stamped with — a resolved bureau referral wins, then a marketing link, else organic.
 */
final class SignupAttribution
{
    public const KEYS = ['source', 'medium', 'campaign'];

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, string>|null the utm values present, or null for none
     */
    public static function fromQuery(array $query): ?array
    {
        $utm = [];
        foreach (self::KEYS as $key) {
            $value = self::clean($query['utm_'.$key] ?? null);
            if ($value !== null) {
                $utm[$key] = $value;
            }
        }

        return $utm === [] ? null : $utm;
    }

    /** @param array<string, string> $utm */
    public static function encode(array $utm): string
    {
        return implode('|', array_map(fn (string $key): string => $utm[$key] ?? '', self::KEYS));
    }

    /** @return array<string, string>|null */
    public static function decode(?string $cookie): ?array
    {
        if ($cookie === null || $cookie === '') {
            return null;
        }
        $parts = explode('|', $cookie);
        $utm = [];
        foreach (self::KEYS as $i => $key) {
            $value = self::clean($parts[$i] ?? null);
            if ($value !== null) {
                $utm[$key] = $value;
            }
        }

        return $utm === [] ? null : $utm;
    }

    /** @param array<string, string>|null $utm */
    public static function source(bool $brokerReferred, ?array $utm): SignupSource
    {
        return match (true) {
            $brokerReferred => SignupSource::Broker,
            $utm !== null => SignupSource::Utm,
            default => SignupSource::Organic,
        };
    }

    private static function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $clean = substr((string) preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($value))), 0, 40);

        // Only "_" / "-" left (e.g. from "; --") is no value at all.
        return preg_match('/[a-z0-9]/', $clean) === 1 ? $clean : null;
    }
}
