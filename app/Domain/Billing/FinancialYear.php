<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The Indian financial year (1 April – 31 March, IST) an instant falls in, as the four digits used
 * in invoice numbers: "2627" for FY 2026-27. Invoice sequences restart each financial year.
 */
final class FinancialYear
{
    public function __construct(private readonly string $timezone = 'Asia/Kolkata') {}

    public function of(CarbonInterface $at): string
    {
        $local = CarbonImmutable::instance($at)->setTimezone($this->timezone);
        $start = $local->month >= 4 ? $local->year : $local->year - 1;

        return sprintf('%02d%02d', $start % 100, ($start + 1) % 100);
    }

    /** "2026-27" for "2627". */
    public function label(string $code): string
    {
        return '20'.substr($code, 0, 2).'-'.substr($code, 2, 2);
    }
}
