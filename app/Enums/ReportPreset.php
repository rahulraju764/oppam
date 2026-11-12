<?php

declare(strict_types=1);

namespace App\Enums;

use App\Domain\Reports\ReportRange;
use Carbon\CarbonImmutable;

/** A09 date presets (P9.1a). Ranges end yesterday (IST): today isn't snapshotted until it is over. */
enum ReportPreset: string
{
    case Last7 = '7d';
    case Last30 = '30d';
    case Last90 = '90d';
    case ThisMonth = 'month';
    case LastMonth = 'last_month';
    case ThisQuarter = 'quarter';
    case YearToDate = 'ytd';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Last7 => __('Last 7 days'),
            self::Last30 => __('Last 30 days'),
            self::Last90 => __('Last 90 days'),
            self::ThisMonth => __('This month'),
            self::LastMonth => __('Last month'),
            self::ThisQuarter => __('This quarter'),
            self::YearToDate => __('Year to date'),
            self::Custom => __('Custom'),
        };
    }

    /** The range this preset means today; null for Custom. */
    public function range(?CarbonImmutable $now = null): ?ReportRange
    {
        $today = ($now ?? CarbonImmutable::now())->setTimezone((string) config('oppam.display_timezone'))->startOfDay();
        $end = $today->subDay();

        $range = match ($this) {
            self::Last7 => new ReportRange($end->subDays(6), $end),
            self::Last30 => new ReportRange($end->subDays(29), $end),
            self::Last90 => new ReportRange($end->subDays(89), $end),
            self::ThisMonth => new ReportRange($today->startOfMonth(), $end),
            self::LastMonth => new ReportRange($today->subMonthNoOverflow()->startOfMonth(), $today->startOfMonth()->subDay()),
            self::ThisQuarter => new ReportRange($today->firstOfQuarter(), $end),
            self::YearToDate => new ReportRange($today->startOfYear(), $end),
            self::Custom => null,
        };

        // On the 1st of a month / quarter / year "this …" has no finished day yet: show that day.
        return $range !== null && $range->from->greaterThan($range->to) ? new ReportRange($range->from, $range->from) : $range;
    }

    /** @return array<string, string> value => label, for the select */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $p): array => [$p->value => $p->label()])->all();
    }
}
