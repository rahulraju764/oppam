<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Enums\ReportMetric;
use Illuminate\Support\Facades\DB;

/** Number helpers shared by the A09 reports (P9.1a). */
trait FormatsReportNumbers
{
    /** $part as a % of $whole, one decimal; null when $whole is 0. */
    protected static function pct(int|float $part, int|float $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }

    protected static function n(int|float|null $value): string
    {
        return $value === null ? '—' : number_format((float) $value, is_float($value) && floor($value) !== $value ? 1 : 0);
    }

    protected static function p(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 1).'%';
    }

    /** "13 Nov 2026" — the first day the activity capture holds (P9.1a went live), else today. */
    protected static function trackedSince(): string
    {
        return (ReportMetric::capturedSince('member_activity_days') ?? now((string) config('oppam.display_timezone')))->format('d M Y');
    }

    /**
     * District labels for ids found in dimensions ("none" → Not set).
     *
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    protected static function districtLabels(array $ids): array
    {
        $numeric = array_values(array_filter($ids, fn (string $id): bool => ctype_digit($id)));
        $labels = $numeric === [] ? [] : DB::table('master_districts')->whereIn('id', $numeric)->pluck('label', 'id')->all();

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = $id === 'none' ? __('Not set yet') : (string) ($labels[$id] ?? __('Unknown'));
        }

        return $out;
    }
}
