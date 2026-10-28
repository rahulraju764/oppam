<?php

declare(strict_types=1);

namespace App\Domain\Broker\Import;

use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use ZipArchive;

/**
 * Reads an uploaded import file (§11A B.9): the header row and the data rows of the first sheet
 * (the *Profiles* sheet when present). Values are read RAW — formulas are never calculated, dates
 * stay Excel serial numbers for CellParser — and fully empty rows are skipped. Data rows keep their
 * sheet row number (what the bureau sees in Excel).
 */
final class ImportSheetReader
{
    /**
     * @return array{headers: list<string>, rows: array<int, list<mixed>>} rows: sheet row number => cells
     */
    public function read(string $path, string $extension): array
    {
        if ($extension === 'csv') {
            $reader = new Csv;
            $reader->setInputEncoding('UTF-8');
        } else {
            $reader = new Xlsx;
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
            // Load ONE sheet — the same one tooLarge() measured (Profiles, else the first).
            $names = $reader->listWorksheetNames($path);
            $reader->setLoadSheetsOnly(in_array('Profiles', $names, true) ? 'Profiles' : ($names[0] ?? 'Profiles'));
        }

        $book = $reader->load($path);
        try {
            $grid = $book->getSheet(0)->toArray(null, false, false, false);   // raw: no formula calculation, no formatting
        } finally {
            $book->disconnectWorksheets();   // cyclic refs: without this every batch leaks the workbook in a long-lived worker
        }

        $headers = null;
        $rows = [];
        foreach ($grid as $i => $cells) {
            $cells = array_values($cells);
            if (array_filter($cells, fn (mixed $c): bool => CellParser::text($c) !== '') === []) {
                continue;
            }
            if ($headers === null) {
                $headers = array_map(fn (mixed $c): string => preg_replace('/^\xEF\xBB\xBF/', '', CellParser::text($c)) ?? '', $cells);

                continue;
            }
            $rows[$i + 1] = $cells;
        }

        return ['headers' => $headers ?? [], 'rows' => $rows];
    }

    /**
     * Too big to load? Decided from the sheet's dimension (xlsx) or a line count (csv) WITHOUT
     * loading its cells — a 10 MB file can hold millions of them. Allows some blank / formatted
     * rows beyond the limit; the exact data-row count is checked after loading.
     */
    public function tooLarge(string $path, string $extension, int $maxRows): bool
    {
        $ceiling = $maxRows * 2 + 50;

        if ($extension === 'csv') {
            $lines = 0;
            $in = fopen($path, 'rb');
            while ($in !== false && fgets($in) !== false) {
                if (++$lines > $ceiling) {
                    break;
                }
            }
            if ($in !== false) {
                fclose($in);
            }

            return $lines > $ceiling;
        }

        $reader = new Xlsx;
        $sheets = $reader->listWorksheetInfo($path);
        $info = collect($sheets)->firstWhere('worksheetName', 'Profiles') ?? ($sheets[0] ?? null);

        return $info === null || (int) $info['totalRows'] > $ceiling || (int) $info['totalColumns'] > 200;
    }

    /**
     * File names inside the photo zip (directories skipped), for photo-name matching.
     *
     * @return list<string>
     */
    public static function zipEntries(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return [];
        }

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (! str_ends_with($name, '/')) {
                $names[] = $name;
            }
        }
        $zip->close();

        return $names;
    }
}
