<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Data\Audit\AuditDiffLineData;
use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;

/**
 * The before / after diff of one audit row (PRD A12 "before/after diff, redacted"; P9.2a). Nested
 * values are flattened to dotted keys; keys present on either side are listed, changed ones first
 * in key order. Values were redacted when written; they are redacted AGAIN here, so a row written
 * before a key joined the secret list still never shows it.
 */
final class AuditDiff
{
    /** Longest value shown per side (the full row stays in the export). */
    private const MAX_VALUE = 500;

    public function __construct(private readonly AuditLogger $logger) {}

    /** @return list<AuditDiffLineData> */
    public function lines(AuditLog $entry): array
    {
        $before = self::flatten($this->logger->redact($entry->before ?? []));
        $after = self::flatten($this->logger->redact($entry->after ?? []));
        $lines = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $old = array_key_exists($key, $before) ? self::text($before[$key]) : '';
            $new = array_key_exists($key, $after) ? self::text($after[$key]) : '';
            $lines[] = new AuditDiffLineData((string) $key, $old, $new, ! array_key_exists($key, $before) || ! array_key_exists($key, $after) || $before[$key] !== $after[$key]);
        }

        usort($lines, fn (AuditDiffLineData $a, AuditDiffLineData $b): int => [$b->changed, $a->key] <=> [$a->changed, $b->key]);

        return $lines;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<string, mixed>
     */
    public static function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];
        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $flat += self::flatten($value, $path);
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }

    private static function text(mixed $value): string
    {
        $text = match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };

        return mb_strlen($text) > self::MAX_VALUE ? mb_substr($text, 0, self::MAX_VALUE).'…' : $text;
    }
}
