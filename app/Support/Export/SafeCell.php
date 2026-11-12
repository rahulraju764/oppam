<?php

declare(strict_types=1);

namespace App\Support\Export;

/**
 * Formula neutralisation for exported text (A09 "CSV/Excel export with formula neutralisation",
 * OWASP CSV injection): a cell starting with = + - @ tab or CR gets a leading apostrophe, so a
 * spreadsheet shows it as text instead of running it. Numbers are left alone. The same rule as
 * the older copies in ExportMembers / CellParser / MasterExportController (to be unified, P9.4).
 */
final class SafeCell
{
    public static function neutralise(string|int|float|null $value): string|int|float
    {
        if ($value === null) {
            return '';
        }
        if (! is_string($value)) {
            return $value;
        }

        return $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'".$value : $value;
    }
}
